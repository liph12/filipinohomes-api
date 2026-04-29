<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use App\Models\Property;
use App\Models\Listing;
use App\Models\Project;
use App\Models\Agent;
use App\Models\Office;
use App\Models\Magazine;
use App\Models\User;
use App\Models\PageBuilder;

class CompressLegacyImages extends Command
{
    protected $signature = 'images:compress-legacy
                            {--dry-run      : Show what would be compressed without making changes}
                            {--chunk=100    : DB records per chunk}
                            {--kb=50        : Compress images larger than this KB (smaller ones are copied as-is)}
                            {--dest=fh-compressed : Destination folder in S3 for compressed copies}';

    protected $description = 'Re-upload existing S3 images > --kb threshold as compressed WebP copies (originals untouched)';

    private string $awsUrl;     // source bucket URL  (AWS  / old)
    private string $destAwsUrl; // destination bucket URL (AWS2 / new)
    private string $dest;
    private int $checked    = 0;
    private int $compressed = 0;
    private int $copied     = 0;
    private int $skipped    = 0;
    private int $failed     = 0;

    /** @var resource|false */
    private $logFile = false;

    // Columns that store JSON arrays of URLs
    private array $arrayColumns = [
        [Property::class,   'photos'],
        [Listing::class,    'featured_photo'],
        [Project::class,    'featured_photo'],
        [Project::class,    'photos_url'],
        [Agent::class,      'avatar'],
        [Office::class,     'photo'],
        [Magazine::class,   'cover_photo'],
        [PageBuilder::class,'banner'],
        [PageBuilder::class,'gallery'],
    ];

    // Columns that store a single URL string
    private array $stringColumns = [
        [User::class, 'avatar'],
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $logPath       = storage_path('logs/compress-legacy-' . date('Y-m-d-His') . '.log');
        $this->logFile = fopen($logPath, 'w');
        $this->info("Logging to: {$logPath}");

        $this->awsUrl     = rtrim(env('AWS_URL', ''), '/');
        $this->destAwsUrl = rtrim(env('AWS2_URL', ''), '/');
        $this->dest       = trim((string) $this->option('dest'), '/');
        $threshold        = (int) $this->option('kb') * 1024;
        $chunk            = (int) $this->option('chunk');
        $dryRun           = (bool) $this->option('dry-run');

        if (! $this->awsUrl) {
            $this->error('AWS_URL is not set in .env');
            if ($this->logFile) fclose($this->logFile);
            return 1;
        }

        if (! $this->destAwsUrl) {
            $this->error('AWS2_URL is not set in .env');
            if ($this->logFile) fclose($this->logFile);
            return 1;
        }

        $header = sprintf(
            '%sThreshold: %dKB | Dest: /%s | Chunk: %d',
            $dryRun ? '[DRY RUN] ' : '',
            $this->option('kb'),
            $this->dest,
            $chunk
        );
        $this->info($header);
        $this->log($header);
        $this->newLine();

        // Suppress model observers on Property for all Property-touching loops so
        // retrieved observers (e.g. ATS expiry sync) don't fire on every chunk row.
        Property::withoutEvents(function () use ($threshold, $chunk, $dryRun) {
            foreach ($this->arrayColumns as [$model, $column]) {
                $this->processArrayColumn($model, $column, $threshold, $chunk, $dryRun);
            }

            foreach ($this->stringColumns as [$model, $column]) {
                $this->processStringColumn($model, $column, $threshold, $chunk, $dryRun);
            }

            $this->processAtsAttachments($threshold, $chunk, $dryRun);
        });

        $this->newLine();
        $summary = sprintf(
            'Checked: %d | Compressed: %d | Copied: %d | Skipped: %d | Failed: %d',
            $this->checked, $this->compressed, $this->copied, $this->skipped, $this->failed
        );
        $this->table(
            ['Checked', 'Compressed (> threshold)', 'Copied (≤ threshold)', 'Skipped', 'Failed'],
            [[$this->checked, $this->compressed, $this->copied, $this->skipped, $this->failed]]
        );
        $this->log($summary);

        if ($this->logFile) {
            fclose($this->logFile);
        }

        return 0;
    }

    private function processArrayColumn(
        string $model,
        string $column,
        int $threshold,
        int $chunk,
        bool $dryRun
    ): void {
        $table = (new $model)->getTable();
        $label = "→ {$table}.{$column} (array)";
        $this->line("<fg=cyan>{$label}</>");
        $this->log($label);

        $model::whereNotNull($column)
            ->chunkById($chunk, function ($rows) use ($column, $threshold, $dryRun) {
                foreach ($rows as $row) {
                    $urls    = (array) ($row->$column ?? []);
                    $newUrls = [];
                    $changed = false;

                    foreach ($urls as $url) {
                        if ($url === null) { $newUrls[] = null; continue; }
                        $result    = $this->processUrl((string) $url, $threshold, $dryRun);
                        $newUrls[] = $result;
                        if ($result !== (string) $url) $changed = true;
                    }

                    if ($changed && ! $dryRun) {
                        $row->updateQuietly([$column => $newUrls]);
                    }
                }
            });
    }

    private function processStringColumn(
        string $model,
        string $column,
        int $threshold,
        int $chunk,
        bool $dryRun
    ): void {
        $table = (new $model)->getTable();
        $label = "→ {$table}.{$column} (string)";
        $this->line("<fg=cyan>{$label}</>");
        $this->log($label);

        $model::whereNotNull($column)
            ->where($column, '!=', '')
            ->chunkById($chunk, function ($rows) use ($column, $threshold, $dryRun) {
                foreach ($rows as $row) {
                    $result = $this->processUrl((string) ($row->$column ?? ''), $threshold, $dryRun);

                    if ($result !== $row->$column && ! $dryRun) {
                        $row->updateQuietly([$column => $result]);
                    }
                }
            });
    }

    private function processAtsAttachments(int $threshold, int $chunk, bool $dryRun): void
    {
        $label = '→ properties.ats_attachments (photos only)';
        $this->line("<fg=cyan>{$label}</>");
        $this->log($label);

        Property::whereNotNull('ats_attachments')
            ->chunkById($chunk, function ($rows) use ($threshold, $dryRun) {
                foreach ($rows as $row) {
                    $attachments = (array) ($row->ats_attachments ?? []);
                    $photos      = (array) ($attachments['photos'] ?? []);

                    if (empty($photos)) continue;

                    $newPhotos = [];
                    $changed   = false;

                    foreach ($photos as $url) {
                        if ($url === null) { $newPhotos[] = null; continue; }
                        $result      = $this->processUrl((string) $url, $threshold, $dryRun);
                        $newPhotos[] = $result;
                        if ($result !== (string) $url) $changed = true;
                    }

                    if ($changed && ! $dryRun) {
                        $attachments['photos'] = $newPhotos;
                        $row->updateQuietly(['ats_attachments' => $attachments]);
                    }
                }
            });
    }

    private function processUrl(string $url, int $threshold, bool $dryRun): string
    {
        $srcPath = $this->urlToS3Path($url);

        if (! $srcPath) {
            $this->skipped++;
            return $url;
        }

        // Already lives in the new bucket — was migrated in a previous run.
        if (str_starts_with($url, $this->destAwsUrl)) {
            $this->skipped++;
            return $url;
        }

        try {
            if (! Storage::disk('s3')->exists($srcPath)) {
                $msg = "  MISSING   {$srcPath}";
                $this->warn($msg);
                $this->log($msg);
                $this->skipped++;
                return $url;
            }

            $this->checked++;
            $sizeBytes = Storage::disk('s3')->size($srcPath);
            $sizeKb    = round($sizeBytes / 1024);

            if ($sizeBytes <= $threshold) {
                // Small file — download from old bucket, upload as-is to new bucket.
                $ext      = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION) ?: 'jpg');
                $destPath = '/' . $this->dest . '/' . Str::uuid() . '.' . $ext;
                $destUrl  = $this->destAwsUrl . $destPath;

                $msg = "  copy      {$sizeKb}KB  {$srcPath}";
                $this->line($msg);
                $this->log($msg);

                if ($dryRun) {
                    $this->copied++;
                    return $url;
                }

                $contents = Storage::disk('s3')->get($srcPath);
                $ok       = Storage::disk('s3_new')->put($destPath, $contents, 'public');
                unset($contents);

                if ($ok) {
                    $this->log("            → {$destPath}");
                    $this->copied++;
                    return $destUrl;
                }

                $msg = "  FAIL (copy)  {$srcPath}";
                $this->error($msg);
                $this->log($msg);
                $this->failed++;
                return $url;
            }

            $destPath = '/' . $this->dest . '/' . Str::uuid() . '.webp';
            $destUrl  = $this->destAwsUrl . $destPath;

            $this->warn("  COMPRESS  {$sizeKb}KB  {$srcPath}");
            $this->line("            → {$destPath}");

            if ($dryRun) {
                $this->log("  DRY-RUN   {$sizeKb}KB  {$srcPath}  → {$destPath}");
                $this->compressed++;
                return $url;
            }

            $contents = Storage::disk('s3')->get($srcPath);
            $manager  = new ImageManager(new Driver());
            $image    = $manager->read($contents)->scaleDown(width: 1200);

            // Reduce quality until output is under threshold or floor (30) is reached.
            // Step of 4 covers: 72,68,64,60,56,52,48,44,40,36,32,30
            $quality     = 72;
            $encodedStr  = '';
            $outputSize  = 0;
            do {
                $encodedStr = (string) $image->toWebp($quality);
                $outputSize = strlen($encodedStr);
                if ($outputSize <= $threshold || $quality === 30) break;
                $quality = max(30, $quality - 4);
            } while (true);

            $uploaded = Storage::disk('s3_new')->put($destPath, $encodedStr, 'public');

            // Free memory immediately — critical for 20k image runs
            unset($contents, $manager, $image, $encodedStr);

            if (! $uploaded) {
                $msg = "  FAIL (S3 put returned false)  {$destPath}";
                $this->error($msg);
                $this->log($msg);
                $this->failed++;
                return $url;
            }

            $newSizeKb = round($outputSize / 1024);
            $msg = "  OK  {$sizeKb}KB → {$newSizeKb}KB  q{$quality}  {$destPath}";
            $this->info("  ✓ {$sizeKb}KB → {$newSizeKb}KB  q{$quality}  {$destPath}");
            $this->log($msg);
            $this->compressed++;

            return $destUrl;

        } catch (\Throwable $e) {
            $msg = "  FAIL  {$srcPath}: {$e->getMessage()}";
            $this->error($msg);
            $this->log($msg);
            $this->failed++;
            return $url;
        }
    }

    private function urlToS3Path(string $url): ?string
    {
        if (! str_starts_with($url, $this->awsUrl)) {
            return null;
        }
        return substr($url, strlen($this->awsUrl));
    }

    private function log(string $message): void
    {
        if ($this->logFile) {
            fwrite($this->logFile, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
        }
    }
}
