<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\PdfOptimizer;

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

    /**
     * Ghostscript pass — compression plus fast-web-view linearization. Lives in
     * PdfOptimizer so the one-off magazines:optimize command runs the exact
     * same pass over files that were uploaded before linearization existed.
     */
    private function compressPdf($file): ?array
    {
        return app(PdfOptimizer::class)->optimize((string) $file->getRealPath());
    }
}
