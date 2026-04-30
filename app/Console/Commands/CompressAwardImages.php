<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class CompressAwardImages extends Command
{
    protected $signature = 'images:compress-awards
                            {--dry-run   : Show what would be compressed without making changes}
                            {--kb=50    : Compress images larger than this KB}
                            {--quality=92: Starting WebP quality (reduces until under threshold or floor 30)}';

    protected $description = 'Compress award images in S3 (fh-web-uploads/awards/) that exceed --kb threshold to WebP';

    private int $checked    = 0;
    private int $compressed = 0;
    private int $skipped    = 0;
    private int $failed     = 0;

    /** @var resource|false */
    private $logFile = false;

    // Award image numbers: 2–104, skipping 31
    private function awardNumbers(): array
    {
        $nums = [];
        for ($n = 2; $n <= 104; $n++) {
            if ($n === 31) continue;
            $nums[] = $n;
        }
        return $nums;
    }

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $logPath       = storage_path('logs/compress-awards-' . date('Y-m-d-His') . '.log');
        $this->logFile = fopen($logPath, 'w');
        $this->info("Logging to: {$logPath}");

        $threshold  = (int) $this->option('kb') * 1024;
        $quality    = (int) $this->option('quality');
        $dryRun     = (bool) $this->option('dry-run');

        $header = sprintf(
            '%sThreshold: %dKB | Starting quality: %d',
            $dryRun ? '[DRY RUN] ' : '',
            $this->option('kb'),
            $quality
        );
        $this->info($header);
        $this->log($header);
        $this->newLine();

        foreach ($this->awardNumbers() as $n) {
            $srcPath  = "fh-web-uploads/awards/{$n}.png";
            $destPath = "filipinohomes-new-awards/{$n}.webp";

            try {
                if (! Storage::disk('s3')->exists($srcPath)) {
                    $msg = "  MISSING   {$srcPath}";
                    $this->warn($msg);
                    $this->log($msg);
                    $this->skipped++;
                    continue;
                }

                $this->checked++;
                $sizeBytes = Storage::disk('s3')->size($srcPath);
                $sizeKb    = round($sizeBytes / 1024);

                if ($sizeBytes <= $threshold) {
                    $msg = "  SKIP      {$sizeKb}KB  {$srcPath}  (under threshold)";
                    $this->line($msg);
                    $this->log($msg);
                    $this->skipped++;
                    continue;
                }

                $msg = "  COMPRESS  {$sizeKb}KB  {$srcPath}  → {$destPath}";
                $this->warn($msg);
                $this->log($msg);

                if ($dryRun) {
                    $this->compressed++;
                    continue;
                }

                $contents   = Storage::disk('s3')->get($srcPath);
                $manager    = new ImageManager(new Driver());
                $image      = $manager->read($contents)->scaleDown(width: 1200);

                $q          = $quality;
                $encodedStr = '';
                $outputSize = 0;
                do {
                    $encodedStr = (string) $image->toWebp($q);
                    $outputSize = strlen($encodedStr);
                    if ($outputSize <= $threshold || $q === 30) break;
                    $q = max(30, $q - 4);
                } while (true);

                $uploaded = Storage::disk('s3')->put($destPath, $encodedStr, 'public');

                unset($contents, $manager, $image, $encodedStr);

                if (! $uploaded) {
                    $msg = "  FAIL (S3 put)  {$destPath}";
                    $this->error($msg);
                    $this->log($msg);
                    $this->failed++;
                    continue;
                }

                $newSizeKb = round($outputSize / 1024);
                $ok = "  OK  {$sizeKb}KB → {$newSizeKb}KB  q{$q}  {$destPath}";
                $this->info($ok);
                $this->log($ok);
                $this->compressed++;

            } catch (\Throwable $e) {
                $msg = "  FAIL  {$srcPath}: {$e->getMessage()}";
                $this->error($msg);
                $this->log($msg);
                $this->failed++;
            }
        }

        $this->newLine();
        $this->table(
            ['Checked', 'Compressed (> threshold)', 'Skipped', 'Failed'],
            [[$this->checked, $this->compressed, $this->skipped, $this->failed]]
        );

        if ($this->logFile) fclose($this->logFile);

        return 0;
    }

    private function log(string $message): void
    {
        if ($this->logFile) {
            fwrite($this->logFile, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
        }
    }
}
