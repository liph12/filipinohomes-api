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
                            {--kb=50        : Target output size in KB; quality steps down until met or floor (q30) is reached}
                            {--dest=filipinohomes-compressed-from-old : Destination folder in S3 for compressed copies}
                            {--external=    : Additional URL prefix to process (e.g. https://lh3.googleusercontent.com)}';

    protected $description = 'Convert all S3/external images to compressed WebP and re-upload to the new bucket (originals untouched)';

    private string $awsUrl;          // source bucket URL  (AWS  / old)
    private string $destAwsUrl;      // destination bucket URL (AWS2 / new)
    private string $dest;
    private string $externalPrefix = ''; // optional extra URL prefix (e.g. googleapis)
    private int $checked    = 0;
    private int $compressed = 0;
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
        ini_set('memory_limit', '1G');
        set_time_limit(0);

        $logPath       = storage_path('logs/compress-legacy-' . date('Y-m-d-His') . '.log');
        $this->logFile = fopen($logPath, 'w');
        $this->info("Logging to: {$logPath}");

        $this->awsUrl        = rtrim(env('AWS_URL', ''), '/');
        $this->destAwsUrl    = rtrim(env('AWS2_URL', ''), '/');
        $this->dest          = trim((string) $this->option('dest'), '/');
        $this->externalPrefix = rtrim((string) $this->option('external'), '/');
        $threshold           = (int) $this->option('kb') * 1024;
        $chunk               = (int) $this->option('chunk');
        $dryRun              = (bool) $this->option('dry-run');

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

        if ($this->awsUrl === $this->destAwsUrl) {
            $this->error('AWS_URL and AWS2_URL are identical — source and destination cannot be the same bucket.');
            if ($this->logFile) fclose($this->logFile);
            return 1;
        }

        if (! extension_loaded('gd')) {
            $this->error('The GD PHP extension is required but not loaded.');
            if ($this->logFile) fclose($this->logFile);
            return 1;
        }

        if ($this->externalPrefix && ! ini_get('allow_url_fopen')) {
            $this->error('allow_url_fopen is disabled in php.ini — external URL downloads will fail. Enable it or remove --external.');
            if ($this->logFile) fclose($this->logFile);
            return 1;
        }

        $header = sprintf(
            '%sThreshold: %dKB | Dest: /%s | Chunk: %d%s',
            $dryRun ? '[DRY RUN] ' : '',
            $this->option('kb'),
            $this->dest,
            $chunk,
            $this->externalPrefix ? ' | External: ' . $this->externalPrefix : ''
        );
        $this->info($header);
        $this->log($header);
        $this->newLine();

        // Collect every model class touched so we can suppress their observers.
        // withoutEvents() is scoped per-class — calling it only on Property would
        // leave retrieved/updated observers firing on Listing, Agent, etc.
        $allModels = array_unique(array_merge(
            array_column($this->arrayColumns, 0),
            array_column($this->stringColumns, 0),
            [Property::class]
        ));

        $run = function () use ($threshold, $chunk, $dryRun) {
            foreach ($this->arrayColumns as [$model, $column]) {
                $this->processArrayColumn($model, $column, $threshold, $chunk, $dryRun);
            }
            foreach ($this->stringColumns as [$model, $column]) {
                $this->processStringColumn($model, $column, $threshold, $chunk, $dryRun);
            }
            $this->processAtsAttachments($threshold, $chunk, $dryRun);
        };

        // Nest withoutEvents for each model so all observers are suppressed.
        foreach (array_reverse($allModels) as $modelClass) {
            $inner = $run;
            $run   = fn () => $modelClass::withoutEvents($inner);
        }

        $run();

        $this->newLine();
        $summary = sprintf(
            'Checked: %d | Converted: %d | Skipped: %d | Failed: %d',
            $this->checked, $this->compressed, $this->skipped, $this->failed
        );
        $this->table(
            ['Checked', 'Converted to WebP', 'Skipped', 'Failed'],
            [[$this->checked, $this->compressed, $this->skipped, $this->failed]]
        );
        $this->log($summary);

        if ($this->logFile) {
            fclose($this->logFile);
        }

        return $this->failed > 0 ? 1 : 0;
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
        $label = '→ properties.ats_attachments (photos only — documents/PDFs are skipped intentionally)';
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
        // Already lives in the destination bucket — migrated in a previous run.
        if (str_starts_with($url, $this->destAwsUrl)) {
            $this->skipped++;
            return $url;
        }

        $srcPath    = $this->urlToS3Path($url);
        $isS3       = $srcPath !== null;
        $isExternal = ! $isS3
            && $this->externalPrefix !== ''
            && str_starts_with($url, $this->externalPrefix);

        if (! $isS3 && ! $isExternal) {
            $msg = "  SKIP (unrecognized host)  {$url}";
            $this->line($msg);
            $this->log($msg);
            $this->skipped++;
            return $url;
        }

        $srcLabel  = $isS3 ? $srcPath : $url;
        $contents  = '';

        try {
            // --- Dry run: log intent without downloading anything ---
            if ($dryRun) {
                $this->checked++;
                $msg = "  DRY-RUN   {$srcLabel}";
                $this->line($msg);
                $this->log($msg);
                $this->compressed++;
                return $url;
            }

            // --- Download content (S3 or external) ---
            if ($isS3) {
                $contents = Storage::disk('s3')->get($srcPath);
                if ($contents === null) {
                    $msg = "  MISSING   {$srcPath}";
                    $this->warn($msg);
                    $this->log($msg);
                    $this->skipped++;
                    return $url;
                }
            } else {
                // Public HTTP download — no API key needed for googleapis public images
                $ctx      = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1]]);
                $contents = @file_get_contents($url, false, $ctx);
                if ($contents === false) {
                    $msg = "  FAIL (connection error)  {$url}";
                    $this->error($msg);
                    $this->log($msg);
                    $this->failed++;
                    return $url;
                }
                // file_get_contents returns the body even for 4xx/5xx — check status explicitly
                preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $m);
                $httpStatus = (int) ($m[1] ?? 200);
                if ($httpStatus >= 400) {
                    $msg = "  FAIL (HTTP {$httpStatus})  {$url}";
                    $this->warn($msg);
                    $this->log($msg);
                    $this->failed++;
                    return $url;
                }
            }
            $sizeBytes = strlen($contents);

            $this->checked++;
            $sizeKb = round($sizeBytes / 1024);

            // --- All files: convert to WebP ---
            $destPath = '/' . $this->dest . '/' . Str::uuid() . '.webp';
            $destUrl  = $this->destAwsUrl . $destPath;

            $this->line("  → webp    {$sizeKb}KB  {$srcLabel}");
            $this->log("  → webp    {$sizeKb}KB  {$srcLabel}");

            $manager = new ImageManager(new Driver());
            $image   = $manager->read($contents)->scaleDown(width: 1200);
            unset($contents); // free raw download — decoded pixel data is now in $image

            // Reduce quality until output is under threshold or floor (30) is reached.
            $quality    = 72;
            $encodedStr = '';
            $outputSize = 0;
            do {
                $encodedStr = (string) $image->toWebp($quality);
                $outputSize = strlen($encodedStr);
                if ($outputSize <= $threshold || $quality === 30) break;
                $quality = max(30, $quality - 4);
            } while (true);

            unset($manager, $image); // free decoded pixel data before S3 upload

            $uploaded = Storage::disk('s3_new')->put($destPath, $encodedStr, 'public');
            unset($encodedStr);

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
            $msg = "  FAIL  {$srcLabel}: {$e->getMessage()}";
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
