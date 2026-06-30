<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\Property;
use App\Services\ImageVariantService;
use App\Support\VariantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates responsive WebP width-variants for ONE property + all its
 * listings (or orphan listings), then flags each record so the API begins
 * emitting srcset for it. Modeled on MigrateListingPhotosJob: per-property
 * transaction + unique-URL dedup (a photo shared across property/listings is
 * generated once). Idempotent — existing variants are skipped; re-runnable
 * with --reprocess. A record is flagged ONLY when every one of its
 * bucket-resident photos has all variants, so the API never emits a srcset
 * that 404s.
 */
class GenerateImageVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 1200;

    public function __construct(
        public ?int $propertyId = null,
        public array $orphanListingIds = [],
        public bool $dryRun = false,
    ) {
    }

    public function handle(): void
    {
        @ini_set('memory_limit', '1024M');
        $this->process(null);
    }

    public function handleInline(?Command $cmd = null): void
    {
        @ini_set('memory_limit', '1024M');
        $this->process($cmd);
    }

    private function process(?Command $cmd): void
    {
        if ($this->propertyId !== null) {
            $this->processProperty($this->propertyId, $cmd);
            return;
        }
        foreach ($this->orphanListingIds as $id) {
            $this->processOrphanListing((int) $id, $cmd);
        }
    }

    private function processProperty(int $propertyId, ?Command $cmd): void
    {
        DB::transaction(function () use ($propertyId, $cmd) {
            $property = Property::lockForUpdate()->find($propertyId);
            if (!$property) {
                $this->say($cmd, "skip property={$propertyId} (not found)");
                return;
            }
            $listings = Listing::where('property_id', $propertyId)->lockForUpdate()->get();

            // Generate once for the unique bucket URLs across property + listings.
            $unique = $this->bucketUrls(array_merge(
                $this->normalizeArray($property->photos),
                $listings->flatMap(fn ($l) => $this->normalizeArray($l->featured_photo))->all(),
            ));
            $ok = $this->ensureVariants($unique, $cmd);

            if ($this->recordReady($this->normalizeArray($property->photos), $ok)) {
                if (!$this->dryRun) {
                    $property->updateQuietly(['photos_variants_generated_at' => now()]);
                }
                $this->say($cmd, "flagged property={$propertyId}");
            }
            foreach ($listings as $listing) {
                if ($this->recordReady($this->normalizeArray($listing->featured_photo), $ok)) {
                    if (!$this->dryRun) {
                        $listing->updateQuietly(['photos_variants_generated_at' => now()]);
                    }
                }
            }
        });
    }

    private function processOrphanListing(int $listingId, ?Command $cmd): void
    {
        DB::transaction(function () use ($listingId, $cmd) {
            $listing = Listing::lockForUpdate()->find($listingId);
            if (!$listing) {
                $this->say($cmd, "skip orphan listing={$listingId} (not found)");
                return;
            }
            $unique = $this->bucketUrls($this->normalizeArray($listing->featured_photo));
            $ok = $this->ensureVariants($unique, $cmd);
            if ($this->recordReady($this->normalizeArray($listing->featured_photo), $ok)) {
                if (!$this->dryRun) {
                    $listing->updateQuietly(['photos_variants_generated_at' => now()]);
                }
                $this->say($cmd, "flagged orphan listing={$listingId}");
            }
        });
    }

    /** URLs that live on our bucket (others can't get variants), deduped. */
    private function bucketUrls(array $urls): array
    {
        $out = [];
        foreach ($urls as $u) {
            if (VariantUrl::keyFromUrl($u) !== null) {
                $out[$u] = $u;
            }
        }
        return array_values($out);
    }

    /** Ensure variants exist for each URL. Returns map url => bool(success). */
    private function ensureVariants(array $urls, ?Command $cmd): array
    {
        $svc = app(ImageVariantService::class);
        $result = [];
        foreach ($urls as $url) {
            $key = VariantUrl::keyFromUrl($url);
            if ($key === null) {
                $result[$url] = false;
                continue;
            }
            if ($svc->allVariantsExist($key)) {
                $result[$url] = true;
                continue;
            }
            if ($this->dryRun) {
                $this->say($cmd, "  would generate variants for {$key}");
                $result[$url] = true;
                continue;
            }
            $bytes = $this->fetchBytes($url, $cmd);
            if ($bytes === null) {
                $result[$url] = false;
                continue;
            }
            $written = $svc->generateVariants($bytes, $key);
            $result[$url] = $written > 0 && $svc->allVariantsExist($key);
            $this->say($cmd, "  variants={$written} {$key}");
        }
        return $result;
    }

    /** Ready iff every bucket photo of the record generated OK this run. */
    private function recordReady(array $photos, array $ok): bool
    {
        foreach ($photos as $u) {
            if (VariantUrl::keyFromUrl($u) === null) {
                continue; // external/legacy — falls back to original, doesn't block flagging
            }
            if (!($ok[$u] ?? false)) {
                return false;
            }
        }
        return true;
    }

    private function fetchBytes(string $url, ?Command $cmd): ?string
    {
        try {
            $resp = Http::timeout(30)->retry(2, 1000)->get($url);
        } catch (Throwable $e) {
            $this->say($cmd, "  skip {$url} (GET failed: {$e->getMessage()})");
            return null;
        }
        if (!$resp->ok()) {
            $this->say($cmd, "  skip {$url} (GET {$resp->status()})");
            return null;
        }
        $body = $resp->body();
        return strlen($body) > 0 ? $body : null;
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($v) => is_string($v) && trim($v) !== ''));
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, fn ($v) => is_string($v) && trim($v) !== ''));
            }
            return [$value];
        }
        return [];
    }

    private function say(?Command $cmd, string $msg): void
    {
        if ($cmd) {
            $cmd->line($msg);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('images:generate-variants job failed', [
            'property_id' => $this->propertyId,
            'orphan_ids' => $this->orphanListingIds,
            'error' => $e->getMessage(),
        ]);
    }
}
