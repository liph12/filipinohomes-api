<?php

namespace App\Natcon\Services;

use App\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use Aws\Rekognition\Exception\RekognitionException;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Face indexing + search over an event's gallery, backed by AWS Rekognition
 * face collections.
 *
 * Rekognition never scans the bucket: only photos explicitly indexed here are
 * searchable, and each convention's vectors live in their own collection
 * (NatconEvent::galleryCollectionId()), so a selfie search is scoped to one
 * convention by construction. IndexFaces reads the photo straight from S3 —
 * the client and the bucket must therefore be in the SAME region, which is why
 * the region below comes from the s3 disk config rather than its own env knob.
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
     * Which Rekognition collection a gallery scope's vectors live in: the
     * convention's own (NatconEvent::galleryCollectionId()), or ONE shared
     * collection for the public albums (natcon_event_id NULL) —
     * fh-natcon-gallery-public by default: the IAM policy only grants
     * Rekognition on fh-natcon-gallery-* / fh-gallery-* ARNs, and the latter
     * prefix is what natcon:purge-album-pile sweeps. See config/natcon.php.
     */
    public static function collectionFor(?NatconEvent $event): string
    {
        return $event
            ? $event->galleryCollectionId()
            : (string) config('natcon.gallery.public_collection', 'fh-public-gallery');
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
    public function indexPhoto(GalleryPhoto $photo): int
    {
        $collection = self::collectionFor($photo->event);
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
    public function searchByImage(?NatconEvent $event, string $imageBytes): array
    {
        try {
            $result = $this->client->searchFacesByImage([
                'CollectionId' => self::collectionFor($event),
                'Image' => ['Bytes' => $imageBytes],
                'FaceMatchThreshold' => (float) config('natcon.gallery.match_threshold', 90),
                'MaxFaces' => (int) config('natcon.gallery.max_matches', 100),
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
        // entry per PHOTO, keeping the best similarity. face_area is the
        // matched face's bounding box as a fraction of the frame (w × h, so
        // 0.25 = a quarter of the photo) — it lets the UI rank a portrait of
        // the person above a group shot where they are a speck at the back.
        $byPhoto = [];
        foreach ($result['FaceMatches'] ?? [] as $match) {
            $photoId = (int) ($match['Face']['ExternalImageId'] ?? 0);
            if ($photoId <= 0) {
                continue;
            }
            $similarity = (float) ($match['Similarity'] ?? 0);
            $box = $match['Face']['BoundingBox'] ?? [];
            $area = max(0.0, (float) ($box['Width'] ?? 0)) * max(0.0, (float) ($box['Height'] ?? 0));
            if (! isset($byPhoto[$photoId]) || $similarity > $byPhoto[$photoId]['similarity']) {
                $byPhoto[$photoId] = ['similarity' => $similarity, 'face_area' => $area];
            }
        }

        return self::sortMatches($byPhoto);
    }

    /**
     * Best similarity first — the contract every caller relies on.
     *
     * @param  array<int, array{similarity: float, face_area: float}>  $matches
     * @return array<int, array{similarity: float, face_area: float}>
     */
    private static function sortMatches(array $matches): array
    {
        uasort($matches, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

        return $matches;
    }

    /**
     * Evict a deleted photo's vectors so it can never match again. Missing
     * collection or already-deleted faces are fine — the goal state is reached.
     */
    public function forgetPhoto(GalleryPhoto $photo): void
    {
        $faceIds = $photo->face_ids ?? [];
        if ($faceIds === []) {
            return;
        }

        try {
            $this->client->deleteFaces([
                'CollectionId' => self::collectionFor($photo->event),
                'FaceIds' => $faceIds,
            ]);
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
    }

    /**
     * Fold per-face result maps into one photoId => match map, best first.
     *
     * mode 'all' is a set intersection scored by the MINIMUM similarity (the
     * weakest link is what "all of them are in this shot" rests on) and the
     * SMALLEST matched face (same reasoning — the person hardest to see);
     * 'any' is a union scored by the MAX of both.
     *
     * @param  array<int, array<int, array{similarity: float, face_area: float}>>  $perFace
     * @return array<int, array{similarity: float, face_area: float}>
     */
    public function combineMatches(array $perFace, string $mode): array
    {
        if (count($perFace) === 1) {
            return $perFace[0];
        }

        $combined = [];

        if ($mode === 'any') {
            foreach ($perFace as $matches) {
                foreach ($matches as $photoId => $m) {
                    $cur = $combined[$photoId] ?? ['similarity' => 0.0, 'face_area' => 0.0];
                    $combined[$photoId] = [
                        'similarity' => max($cur['similarity'], $m['similarity']),
                        'face_area' => max($cur['face_area'], $m['face_area']),
                    ];
                }
            }
        } else {
            // 'all': survive only in every face's result set.
            $combined = array_shift($perFace);
            foreach ($perFace as $matches) {
                $next = [];
                foreach ($combined as $photoId => $m) {
                    if (isset($matches[$photoId])) {
                        $next[$photoId] = [
                            'similarity' => min($m['similarity'], $matches[$photoId]['similarity']),
                            'face_area' => min($m['face_area'], $matches[$photoId]['face_area']),
                        ];
                    }
                }
                $combined = $next;
                if ($combined === []) {
                    break;
                }
            }
        }

        return self::sortMatches($combined);
    }

    /**
     * Every collection id in the account's region. Needs the account-level
     * rekognition:ListCollections permission (Resource "*") — collection-arn
     * scoped policies don't cover it, so callers must handle AccessDenied.
     *
     * @return string[]
     */
    public function listCollections(): array
    {
        $ids = [];
        $token = null;

        do {
            $result = $this->client->listCollections(array_filter(['NextToken' => $token]));
            $ids = array_merge($ids, $result['CollectionIds'] ?? []);
            $token = $result['NextToken'] ?? null;
        } while ($token);

        return $ids;
    }

    /**
     * Delete an entire face collection — vectors bill monthly, so a retired
     * collection must actually go, not just stop being searched. Missing =
     * already gone = the goal state.
     */
    public function deleteCollection(string $collectionId): void
    {
        try {
            $this->client->deleteCollection(['CollectionId' => $collectionId]);
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
    }
}
