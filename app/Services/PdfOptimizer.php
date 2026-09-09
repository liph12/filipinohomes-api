<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Ghostscript pass over an uploaded PDF: compress, subset fonts, and pack the
 * small objects into OBJECT STREAMS.
 *
 * The object streams are what make a magazine open on page one in a moment.
 * Every reader (the site's flipbook and the NATCON page) draws through pdf.js
 * over byte-range requests, and before it shows anything pdf.js validates the
 * LAST page — walking the page tree, one round trip per page object it meets.
 * Ghostscript writes a flat tree, so that is one fetch per page, each costing
 * a whole range chunk. With object streams all those small objects sit packed
 * beside the cross-reference table, so the walk is one or two requests.
 *
 * Measured on the 2024 NATCON issue (94MB InDesign export, 40 pages) at a
 * throttled 20Mbps, time to the first rendered page:
 *   original ............................ never within 30s (later 9.6s with 1MB chunks)
 *   /ebook + -dFastWebView (linearized) . never within 30s — the flat tree, 55 requests
 *   /ebook + object streams ............. 1.3s, 4 range requests, ~1MB
 *
 * Fast web view is therefore deliberately NOT used: Ghostscript cannot combine
 * it with object streams, and with pdf.js it loses.
 *
 * Every failure is a non-result, never an exception — the caller falls back to
 * the original bytes, exactly as the upload route always has.
 */
class PdfOptimizer
{
    /**
     * @return array{status: string, reason?: string|null, contents?: string, original_size?: int|null, optimized_size?: int|null}
     *   status: compressed_file | original_fallback | ghostscript_error
     *
     * @param  bool  $requireSmaller  Uploads keep the original unless Ghostscript
     *                                shrank it (the historical rule). A re-optimise
     *                                whose point is the object-stream layout passes
     *                                false — a same-size repacked file is still the win.
     */
    public function optimize(string $inputPath, bool $requireSmaller = true): array
    {
        if (! filter_var(env('PDF_OPTIMIZATION_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return ['status' => 'original_fallback', 'reason' => 'pdf_optimization_disabled'];
        }

        if (! $inputPath || ! is_file($inputPath)) {
            return ['status' => 'original_fallback', 'reason' => 'invalid_input_file'];
        }

        $outputSeed = tempnam(sys_get_temp_dir(), 'fh-pdf-');
        if ($outputSeed === false) {
            return ['status' => 'original_fallback', 'reason' => 'temp_file_creation_failed'];
        }

        @unlink($outputSeed);
        $outputPath = $outputSeed.'.pdf';
        $originalSize = @filesize($inputPath) ?: null;

        try {
            $process = new Process([
                env('GHOSTSCRIPT_BINARY', 'gs'),
                '-sDEVICE=pdfwrite',
                // 1.5 is the first version with object streams; every viewer
                // since 2003 reads it.
                '-dCompatibilityLevel=1.5',
                '-dWriteObjStms=true',
                '-dWriteXRefStm=true',
                '-dNOPAUSE',
                '-dQUIET',
                '-dBATCH',
                '-dDetectDuplicateImages=true',
                '-dCompressFonts=true',
                '-dSubsetFonts=true',
                '-dAutoRotatePages=/None',
                '-dPDFSETTINGS='.env('GHOSTSCRIPT_PDFSETTINGS', '/ebook'),
                '-sOutputFile='.$outputPath,
                $inputPath,
            ]);

            $process->setTimeout((float) env('GHOSTSCRIPT_TIMEOUT', 180));
            $process->run();

            if (! $process->isSuccessful() || ! is_file($outputPath)) {
                $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

                Log::warning('Ghostscript PDF compression skipped.', ['error' => $error]);

                return [
                    'status' => 'ghostscript_error',
                    'reason' => $error !== '' ? $error : 'ghostscript_process_failed',
                    'original_size' => $originalSize,
                ];
            }

            $optimizedSize = filesize($outputPath);

            if ($optimizedSize === false || $optimizedSize <= 0) {
                return [
                    'status' => 'original_fallback',
                    'reason' => 'optimized_file_empty',
                    'original_size' => $originalSize,
                    'optimized_size' => $optimizedSize ?: null,
                ];
            }

            if ($requireSmaller && ($originalSize === null || $optimizedSize >= $originalSize)) {
                return [
                    'status' => 'original_fallback',
                    'reason' => 'optimized_file_not_smaller',
                    'original_size' => $originalSize,
                    'optimized_size' => $optimizedSize,
                ];
            }

            $contents = file_get_contents($outputPath);

            if ($contents === false) {
                return [
                    'status' => 'original_fallback',
                    'reason' => 'optimized_file_read_failed',
                    'original_size' => $originalSize,
                    'optimized_size' => $optimizedSize,
                ];
            }

            return [
                'status' => 'compressed_file',
                'reason' => 'ghostscript_optimized',
                'contents' => $contents,
                'original_size' => $originalSize,
                'optimized_size' => $optimizedSize,
            ];
        } catch (\Throwable $e) {
            Log::warning('Ghostscript PDF compression failed.', ['message' => $e->getMessage()]);

            return [
                'status' => 'ghostscript_error',
                'reason' => $e->getMessage(),
                'original_size' => $originalSize,
            ];
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }
}
