<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class FileUploadController extends Controller
{
    public function uploadFile(Request $request)
    {
        set_time_limit(900);
        if ($request->hasFile('file')) {

            $request->validate([
                'file' => 'required|file|mimes:pdf|max:204800',
            ]);

            try {
                $file = $request->file('file');
                $subfolder = $request->input('folder');
                $dir = $subfolder
                    ? "/filipinohomes-new/pdf/" . trim($subfolder, '/')
                    : "/filipinohomes-new/pdf";
                $uploadResult = $this->handleS3Upload($file, $dir);

                return response()->json([
                    'success' => true,
                    'message' => 'PDF uploaded successfully!',
                    'filePath' => $uploadResult['filePath'],
                    'compression' => $uploadResult['compression'] ?? null,
                ], 200);

            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload failed',
                    'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
                ], 500);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'No file uploaded.',
            'filePath' => null,
        ]);
    }

    private function handleS3Upload($file, $dir)
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $sanitizedName = Str::slug($originalName);
        $extension = $file->getClientOriginalExtension();

        $fileName = $dir . "/" . $sanitizedName . "-" . Str::random(8) . "." . $extension;
        $compressionResult = $this->compressPdf($file);
        $fileContents = $compressionResult['contents'] ?? file_get_contents($file);

        Log::info('PDF upload compression result.', [
            'file_name' => $file->getClientOriginalName(),
            'upload_path' => $fileName,
            'status' => $compressionResult['status'] ?? 'original_fallback',
            'reason' => $compressionResult['reason'] ?? null,
            'original_size' => $compressionResult['original_size'] ?? @filesize($file->getRealPath()),
            'optimized_size' => $compressionResult['optimized_size'] ?? null,
        ]);

        Storage::disk('s3')->put($fileName, $fileContents, 'public');

        return [
            'filePath' => config('filesystems.disks.s3.url') . $fileName,
            'compression' => [
                'status' => $compressionResult['status'] ?? 'original_fallback',
                'reason' => $compressionResult['reason'] ?? null,
                'original_size' => $compressionResult['original_size'] ?? @filesize($file->getRealPath()),
                'optimized_size' => $compressionResult['optimized_size'] ?? null,
            ],
        ];
    }

    private function compressPdf($file): ?array
    {
        if (! filter_var(env('PDF_OPTIMIZATION_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return [
                'status' => 'original_fallback',
                'reason' => 'pdf_optimization_disabled',
            ];
        }

        $inputPath = $file->getRealPath();
        if (! $inputPath || ! is_file($inputPath)) {
            return [
                'status' => 'original_fallback',
                'reason' => 'invalid_input_file',
            ];
        }

        $outputSeed = tempnam(sys_get_temp_dir(), 'fh-pdf-');
        if ($outputSeed === false) {
            return [
                'status' => 'original_fallback',
                'reason' => 'temp_file_creation_failed',
            ];
        }

        @unlink($outputSeed);
        $outputPath = $outputSeed . '.pdf';
        $originalSize = @filesize($inputPath) ?: null;

        try {
            $process = new Process([
                env('GHOSTSCRIPT_BINARY', 'gs'),
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dNOPAUSE',
                '-dQUIET',
                '-dBATCH',
                '-dDetectDuplicateImages=true',
                '-dCompressFonts=true',
                '-dSubsetFonts=true',
                '-dAutoRotatePages=/None',
                '-dPDFSETTINGS=' . env('GHOSTSCRIPT_PDFSETTINGS', '/ebook'),
                '-sOutputFile=' . $outputPath,
                $inputPath,
            ]);

            $process->setTimeout((float) env('GHOSTSCRIPT_TIMEOUT', 180));
            $process->run();

            if (! $process->isSuccessful() || ! is_file($outputPath)) {
                $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

                Log::warning('Ghostscript PDF compression skipped.', [
                    'error' => $error,
                ]);

                return [
                    'status' => 'ghostscript_error',
                    'reason' => $error !== '' ? $error : 'ghostscript_process_failed',
                    'original_size' => $originalSize,
                ];
            }

            $optimizedSize = filesize($outputPath);

            if ($optimizedSize === false || $originalSize === false || $optimizedSize <= 0 || $optimizedSize >= $originalSize) {
                return [
                    'status' => 'original_fallback',
                    'reason' => 'optimized_file_not_smaller',
                    'original_size' => $originalSize,
                    'optimized_size' => $optimizedSize ?: null,
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
            Log::warning('Ghostscript PDF compression failed.', [
                'message' => $e->getMessage(),
            ]);

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
