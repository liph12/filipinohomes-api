<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One-off backfill: stamps the long-lived CacheControl header onto S3 objects
 * uploaded BEFORE the disk-level default in config/filesystems.php existed.
 * New uploads inherit the header automatically; this walks the existing
 * inventory. Keys are unique per upload (no in-place overwrites), so the
 * immutable directive is safe.
 *
 * S3 has no "update headers" call — the only way to rewrite metadata on an
 * existing object is a self-copy with MetadataDirective=REPLACE, which wipes
 * everything not re-supplied. ContentType and user metadata are therefore
 * carried over from the head response, and ACL public-read is re-asserted
 * (a copy would otherwise reset it to private).
 */
class BackfillS3CacheControl extends Command
{
    protected $signature = 's3:backfill-cache-control
                            {--prefix=      : Only process keys under this prefix (e.g. members/)}
                            {--dry-run      : Report what would change without writing}
                            {--limit=0      : Stop after this many rewrites (0 = unlimited)}
                            {--sleep-ms=50  : Pause between copies so the walk stays gentle on the bucket}';

    protected $description = 'Set the long-lived CacheControl header on existing S3 objects via self-copy.';

    /** Must match the disk-level default in config/filesystems.php. */
    private const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function handle(): int
    {
        $client = Storage::disk('s3')->getClient();
        $bucket = config('filesystems.disks.s3.bucket');
        $prefix = (string) $this->option('prefix');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $sleepMs = (int) $this->option('sleep-ms');

        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $token = null;
        $stop = false;

        do {
            $params = ['Bucket' => $bucket, 'MaxKeys' => 1000];
            if ($prefix !== '') {
                $params['Prefix'] = $prefix;
            }
            if ($token !== null) {
                $params['ContinuationToken'] = $token;
            }
            $page = $client->listObjectsV2($params);

            foreach ($page['Contents'] ?? [] as $object) {
                $key = (string) $object['Key'];
                $scanned++;

                // Per-object guard: one bad key (deleted mid-walk, permission
                // edge case) must not abort the whole backfill.
                try {
                    $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
                    if (($head['CacheControl'] ?? null) === self::CACHE_CONTROL) {
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("[dry-run] would set CacheControl on {$key}");
                    } else {
                        $client->copyObject([
                            'Bucket' => $bucket,
                            'Key' => $key,
                            // Encode per path segment — keys contain spaces and
                            // other URL-special characters, but the slashes must
                            // survive as separators.
                            'CopySource' => $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key))),
                            'MetadataDirective' => 'REPLACE',
                            'CacheControl' => self::CACHE_CONTROL,
                            'ContentType' => $head['ContentType'] ?? 'application/octet-stream',
                            'Metadata' => $head['Metadata'] ?? [],
                            'ACL' => 'public-read',
                        ]);
                        if ($sleepMs > 0) {
                            usleep($sleepMs * 1000);
                        }
                    }
                    $updated++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("Failed on {$key}: {$e->getMessage()}");
                    Log::warning('s3:backfill-cache-control failed for object', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($limit > 0 && $updated >= $limit) {
                    $stop = true;
                    break;
                }
            }

            $token = ($page['IsTruncated'] ?? false)
                ? ($page['NextContinuationToken'] ?? null)
                : null;
        } while ($token !== null && ! $stop);

        $this->info(sprintf(
            '%s %d object(s): %d %s, %d already set, %d failed.',
            $dryRun ? 'Scanned (dry-run)' : 'Scanned',
            $scanned,
            $updated,
            $dryRun ? 'would update' : 'updated',
            $skipped,
            $failed,
        ));

        return self::SUCCESS;
    }
}
