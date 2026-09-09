<?php

namespace App\Console\Commands;

use App\Models\Magazine;
use App\Services\PdfOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Re-run the Ghostscript pass over a magazine that was uploaded before the
 * upload route compressed PDFs and packed them into object streams, and point
 * the record at the result.
 *
 * Why this exists: the 2024 NATCON issue is a 94MB InDesign export. Every
 * reader — the site's flipbook and the NATCON page — draws it through pdf.js
 * over byte ranges, and pdf.js has to touch the header, the cross-reference
 * table, every page object on the way to the LAST page (it validates that
 * before showing anything), and page one's own content. On the original that
 * is ~7MB in ~30 requests; on the re-encoded file it is ~1MB in 4 (13.6MB in
 * total, so the whole issue also downloads in a seventh of the time). See
 * PdfOptimizer for the measurements.
 *
 *   sudo -u www-data php artisan magazines:optimize national-real-estate-convention
 *   sudo -u www-data php artisan magazines:optimize 12 --dry-run
 *
 * The original S3 object is never deleted or overwritten; the new file is
 * uploaded beside it and the record repointed, so the old URL keeps working
 * for anything that cached it.
 */
class OptimizeMagazinePdf extends Command
{
    protected $signature = 'magazines:optimize
        {magazine : The magazine id or slug}
        {--dry-run : Run Ghostscript and report the sizes without uploading or saving}';

    protected $description = 'Compress an existing magazine PDF with Ghostscript and pack it for fast page-one loading';

    public function handle(PdfOptimizer $optimizer): int
    {
        $arg = (string) $this->argument('magazine');
        $magazine = is_numeric($arg)
            ? Magazine::find((int) $arg)
            : Magazine::where('slug', $arg)->first();

        if (! $magazine) {
            $this->error("No magazine matches '{$arg}'.");

            return self::FAILURE;
        }

        $pdfUrl = $magazine->pdf_file[0] ?? null;

        if (! $pdfUrl) {
            $this->error("'{$magazine->title}' has no PDF on file.");

            return self::FAILURE;
        }

        $this->info("#{$magazine->id} {$magazine->title}");
        $this->line("Downloading {$pdfUrl} …");

        $input = tempnam(sys_get_temp_dir(), 'fh-mag-');

        try {
            $response = Http::withOptions(['stream' => true, 'connect_timeout' => 15, 'timeout' => 0])->get($pdfUrl);

            if (! $response->successful()) {
                $this->error("Could not download the PDF (HTTP {$response->status()}).");

                return self::FAILURE;
            }

            // 8KB chunks to disk — never the whole file in memory (see the
            // streamPdf incident note in MagazineController).
            $body = $response->toPsrResponse()->getBody();
            $out = fopen($input, 'wb');
            while (! $body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
            fclose($out);
            $body->close();

            $originalSize = (int) filesize($input);
            $this->line('Running Ghostscript (this can take a few minutes on a large file) …');

            // requireSmaller: false — the object-stream layout is the point even
            // when the file does not shrink.
            $result = $optimizer->optimize($input, requireSmaller: false);
        } finally {
            @unlink($input);
        }

        if ($result['status'] !== 'compressed_file') {
            $this->error('Ghostscript did not produce a file: '.($result['reason'] ?? 'unknown reason'));
            $this->line('Ghostscript must be installed on this machine (e.g. `apt install ghostscript`),');
            $this->line('or set GHOSTSCRIPT_BINARY in .env to its path. PDF_OPTIMIZATION_ENABLED must not be false.');

            return self::FAILURE;
        }

        $optimizedSize = (int) $result['optimized_size'];
        $this->info(sprintf(
            'Original %s → optimized %s (%s%%).',
            $this->human($originalSize),
            $this->human($optimizedSize),
            $originalSize > 0 ? round($optimizedSize / $originalSize * 100) : '?',
        ));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing uploaded, nothing saved.');

            return self::SUCCESS;
        }

        // Beside the original, same folder, distinct name: the old object is
        // left exactly as it is.
        $path = (string) parse_url($pdfUrl, PHP_URL_PATH);
        $dir = trim(dirname($path), '/');
        $base = pathinfo($path, PATHINFO_FILENAME);
        $key = ($dir !== '' && $dir !== '.' ? $dir.'/' : '').$base.'-web-'.Str::random(8).'.pdf';

        Storage::disk('s3')->put($key, $result['contents'], 'public');
        $newUrl = rtrim((string) config('filesystems.disks.s3.url'), '/').'/'.$key;

        // Keeps the array shape the model casts. Saving bumps updated_at, which
        // invalidates streamPdf's local disk cache, and the model's saved hook
        // clears the lookup caches — the next reader gets the new file.
        $magazine->pdf_file = [$newUrl];
        $magazine->auditSource = 'magazines_optimize';
        $magazine->save();

        $this->info("Done. The magazine now serves {$newUrl}");
        $this->line("The original stays at {$pdfUrl} (not deleted).");

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024).' KB';
        }

        return $bytes.' B';
    }
}
