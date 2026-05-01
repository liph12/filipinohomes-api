<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class MigrateBlogImages extends Command
{
    protected $signature = 'blogs:migrate-images
                            {--dry-run        : Show what would be migrated without making changes}
                            {--chunk=50       : DB records per chunk}
                            {--kb=50          : Compress images larger than this KB (smaller ones are copied as-is)}
                            {--quality=92     : Starting WebP quality (reduces until under threshold or floor 30)}
                            {--dest=filipinohomes-blogs : S3 folder prefix for uploaded images}';

    protected $description = 'Download blog featured_image from laravel.filipinohomes.com, compress to WebP if over --kb, upload to S3, and update the DB record. Posts with no image get the default placeholder URL.';

    private const DEFAULT_IMAGE =
        'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/filipinohomes-new/uploader/1c465a2e-3d8e-4d46-8e87-77cbe0d6781a.webp';

    private string $sourceBase = 'https://laravel.filipinohomes.com/images/';
    private string $s3Url;
    private string $dest;

    private int $compressed   = 0;
    private int $copied       = 0;
    private int $defaulted    = 0;
    private int $failed       = 0;

    /** @var resource|false */
    private $logFile = false;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $logPath       = storage_path('logs/migrate-blog-images-' . date('Y-m-d-His') . '.log');
        $this->logFile = fopen($logPath, 'w');
        $this->info("Logging to: {$logPath}");

        $this->s3Url = rtrim(env('AWS_URL', ''), '/');
        $this->dest  = trim((string) $this->option('dest'), '/');
        $chunk       = (int) $this->option('chunk');
        $threshold   = (int) $this->option('kb') * 1024;
        $quality     = (int) $this->option('quality');
        $dryRun      = (bool) $this->option('dry-run');

        if (! $this->s3Url) {
            $this->error('AWS_URL is not set in .env');
            fclose($this->logFile);
            return 1;
        }

        $header = sprintf(
            '%sSource: %s | Dest: %s | Threshold: %dKB | Quality: %d | Chunk: %d',
            $dryRun ? '[DRY RUN] ' : '',
            $this->sourceBase,
            $this->dest,
            $this->option('kb'),
            $quality,
            $chunk
        );
        $this->info($header);
        $this->log($header);
        $this->newLine();

        // Process all posts not yet pointing to an http URL (includes null/blank)
        Post::where(function ($q) {
                $q->whereNull('featured_image')
                  ->orWhere('featured_image', '')
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('featured_image')
                         ->where('featured_image', '!=', '')
                         ->where('featured_image', 'not like', 'http%');
                  });
            })
            ->chunkById($chunk, function ($posts) use ($threshold, $quality, $dryRun) {
                foreach ($posts as $post) {
                    $this->migratePost($post, $threshold, $quality, $dryRun);
                }
            });

        $this->newLine();
        $this->table(
            ['Compressed (> threshold)', 'Copied (≤ threshold)', 'Defaulted (no image)', 'Failed'],
            [[$this->compressed, $this->copied, $this->defaulted, $this->failed]]
        );

        if ($this->logFile) {
            fclose($this->logFile);
        }

        return $this->failed > 0 ? 1 : 0;
    }

    private function migratePost(Post $post, int $threshold, int $quality, bool $dryRun): void
    {
        $relativePath = ltrim((string) $post->featured_image, '/');

        // No image — assign default
        if ($relativePath === '') {
            $msg = "  [{$post->id}] (no image) → DEFAULT";
            $this->line($msg);
            $this->log($msg);

            if (! $dryRun) {
                $post->updateQuietly(['featured_image' => self::DEFAULT_IMAGE]);
            }

            $this->defaulted++;
            return;
        }

        $sourceUrl = $this->sourceBase . $relativePath;

        $msg = "  [{$post->id}] {$relativePath}";
        $this->line($msg);
        $this->log($msg);

        if ($dryRun) {
            $s3Key  = $this->dest . '/' . $this->webpPath($relativePath);
            $newUrl = $this->s3Url . '/' . $s3Key;
            $this->info("    → {$newUrl} (dry-run)");
            $this->compressed++;
            return;
        }

        try {
            $response = Http::timeout(30)->get($sourceUrl);

            if (! $response->successful()) {
                // Source image missing — fall back to default
                $warn = "    NOT FOUND (HTTP {$response->status()}) — using default";
                $this->warn($warn);
                $this->log($warn);
                $post->updateQuietly(['featured_image' => self::DEFAULT_IMAGE]);
                $this->defaulted++;
                return;
            }

            $contents  = $response->body();
            $sizeBytes = strlen($contents);
            $sizeKb    = round($sizeBytes / 1024);

            if ($sizeBytes <= $threshold) {
                // Small file — upload as-is, keep original extension
                $s3Key  = $this->dest . '/' . $relativePath;
                $newUrl = $this->s3Url . '/' . $s3Key;

                $msg = "    copy  {$sizeKb}KB  → {$s3Key}";
                $this->line($msg);
                $this->log($msg);

                $uploaded = Storage::disk('s3')->put($s3Key, $contents, 'public');
                unset($contents);

                if (! $uploaded) {
                    $fail = "    FAIL (S3 put)  {$s3Key}";
                    $this->error($fail);
                    $this->log($fail);
                    $this->failed++;
                    return;
                }

                $post->updateQuietly(['featured_image' => $newUrl]);
                $this->info("    OK (copied) → {$newUrl}");
                $this->log("    OK (copied) → {$newUrl}");
                $this->copied++;
                return;
            }

            // Large file — compress to WebP
            $s3Key  = $this->dest . '/' . $this->webpPath($relativePath);
            $newUrl = $this->s3Url . '/' . $s3Key;

            $manager = new ImageManager(new Driver());
            $image   = $manager->read($contents)->scaleDown(width: 1200);
            unset($contents);

            $q          = $quality;
            $encodedStr = '';
            $outputSize = 0;
            do {
                $encodedStr = (string) $image->toWebp($q);
                $outputSize = strlen($encodedStr);
                if ($outputSize <= $threshold || $q === 30) break;
                $q = max(30, $q - 4);
            } while (true);

            unset($manager, $image);

            $newSizeKb = round($outputSize / 1024);
            $msg = "    COMPRESS  {$sizeKb}KB → {$newSizeKb}KB  q{$q}  → {$s3Key}";
            $this->warn($msg);
            $this->log($msg);

            $uploaded = Storage::disk('s3')->put($s3Key, $encodedStr, 'public');
            unset($encodedStr);

            if (! $uploaded) {
                $fail = "    FAIL (S3 put)  {$s3Key}";
                $this->error($fail);
                $this->log($fail);
                $this->failed++;
                return;
            }

            $post->updateQuietly(['featured_image' => $newUrl]);
            $this->info("    OK → {$newUrl}");
            $this->log("    OK → {$newUrl}");
            $this->compressed++;

        } catch (\Throwable $e) {
            $fail = "    FAIL  {$relativePath}: {$e->getMessage()}";
            $this->error($fail);
            $this->log($fail);
            $this->failed++;
        }
    }

    private function webpPath(string $path): string
    {
        return preg_replace('/\.[^.]+$/', '.webp', $path) ?? $path;
    }

    private function log(string $message): void
    {
        if ($this->logFile) {
            fwrite($this->logFile, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
        }
    }
}
