<?php

namespace App\Natcon\Services;

use App\Natcon\Models\AlbumPhoto;
use App\Natcon\Models\NatconEvent;
use Aws\Rekognition\Exception\RekognitionException;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Face indexing + search over an event's album, backed by AWS Rekognition
 * face collections.
 *
 * Rekognition never scans the bucket: only photos explicitly indexed here are
 * searchable, and each convention's vectors live in their own collection
 * (NatconEvent::albumCollectionId()), so a selfie search is scoped to one
 * album by construction. IndexFaces reads the photo straight from S3 — the
 * client and the bucket must therefore be in the SAME region, which is why the
 * region below comes from the s3 disk config rather than its own env knob.
 */
final class FaceRecognitionService
{
    private RekognitionClient $client;

    public function __construct()
    {
        $this->client = new RekognitionClient([
            'version' => '2016-06-27',
            'region' => (string) config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => (string) config('filesystems.disks.s3.key'),
                'secret' => (string) config('filesystems.disks.s3.secret'),
            ],
        ]);
    }

    /** Idempotent: creating a collection that already exists is a no-op. */
    public function ensureCollection(string $collectionId): void
    {
        try {
            $this->client->createCollection(['CollectionId' => $collectionId]);
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
    }

    /**
     * Index every face in one gallery photo into its event's collection and
     * record the outcome on the row.
     *
     * ExternalImageId is the photo's DB id (S3 keys contain slashes, which the
     * field's charset forbids) — it is what a search hands back to identify the
     * matching photo. Zero faces is a legitimate outcome (venue shots, food
     * pics): the row is stamped indexed with face_count 0 so the sweep stops
     * retrying it.
     */
    public function indexPhoto(AlbumPhoto $photo): int
    {
        $collection = $photo->event->albumCollectionId();
        $this->ensureCollection($collection);

        $result = $this->client->indexFaces([
            'CollectionId' => $collection,
            'Image' => [
                'S3Object' => [
                    'Bucket' => (string) config('filesystems.disks.s3.bucket'),
                    'Name' => $photo->s3_key,
                ],
            ],
            'ExternalImageId' => (string) $photo->id,
            'MaxFaces' => 100,
            'QualityFilter' => 'AUTO',
            'DetectionAttributes' => [],
        ]);

        $faceIds = array_values(array_filter(array_map(
            fn ($record) => $record['Face']['FaceId'] ?? null,
            $result['FaceRecords'] ?? [],
        )));

        $photo->forceFill([
            'face_ids' => $faceIds,
            'face_count' => count($faceIds),
            'faces_indexed_at' => Carbon::now(),
            'index_error' => null,
        ])->save();

        return count($faceIds);
    }

    /**
     * Find the gallery photos a face appears in.
     *
     * Rekognition matches against the LARGEST face in the probe image, so a
     * solo selfie works best — the controller re-encodes the probe small enough
     * for the 5MB Image.Bytes limit before calling this.
     *
     * @return array<int, float> photo id => best similarity (0-100), best first.
     */
    public function searchByImage(NatconEvent $event, string $imageBytes): array
    {
        try {
            $result = $this->client->searchFacesByImage([
                'CollectionId' => $event->albumCollectionId(),
                'Image' => ['Bytes' => $imageBytes],
                'FaceMatchThreshold' => (float) config('natcon.album.match_threshold', 90),
                'MaxFaces' => (int) config('natcon.album.max_matches', 100),
                'QualityFilter' => 'AUTO',
            ]);
        } catch (RekognitionException $e) {
            // No collection yet = nothing has been indexed = no matches. Not an
            // error the person searching can do anything about.
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return [];
            }
            // Rekognition's "there is no face in this picture" — surface it as
            // the user-fixable problem it is rather than a 500.
            if ($e->getAwsErrorCode() === 'InvalidParameterException') {
                throw new RuntimeException(
                    'No face could be detected in that photo. Please use a clear, well-lit selfie.'
                );
            }
            throw $e;
        }

        // A group shot holds many indexed faces; several can match the same
        // person (or the probe can match near-duplicates), so collapse to one
        // score per PHOTO, keeping the best.
        $byPhoto = [];
        foreach ($result['FaceMatches'] ?? [] as $match) {
            $photoId = (int) ($match['Face']['ExternalImageId'] ?? 0);
            if ($photoId <= 0) {
                continue;
            }
            $similarity = (float) ($match['Similarity'] ?? 0);
            $byPhoto[$photoId] = max($byPhoto[$photoId] ?? 0, $similarity);
        }

        arsort($byPhoto);

        return $byPhoto;
    }

    /**
     * Evict a deleted photo's vectors so it can never match again. Missing
     * collection or already-deleted faces are fine — the goal state is reached.
     */
    public function forgetPhoto(AlbumPhoto $photo): void
    {
        $faceIds = $photo->face_ids ?? [];
        if ($faceIds === []) {
            return;
        }

        try {
            $this->client->deleteFaces([
                'CollectionId' => $photo->event->albumCollectionId(),
                'FaceIds' => $faceIds,
            ]);
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
    }
}
