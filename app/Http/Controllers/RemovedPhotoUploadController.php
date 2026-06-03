<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Standalone clone of ImageUploadController's pipeline, used to re-host
 * recovered legacy photos. Kept separate so the public /upload route stays
 * untouched. Accepts either a multipart `file` or a remote `url` (downloaded
 * server-side), compresses to WebP ≤ 50 KB and stores on the new bucket.
 */
class RemovedPhotoUploadController extends Controller
{
    public function upload(Request $request)
    {
        // Re-host from a remote URL (recovering legacy photos).
        if ($request->filled('url')) {
            $request->validate(['url' => 'required|url']);
            try {
                $dir = $request->filled('folder')
                    ? "/filipinohomes-new/" . trim($request->input('folder'), '/')
                    : "/filipinohomes-new";
                $filePath = $this->uploadFromUrl($request->input('url'), $dir);

                return response()->json([
                    'success' => true,
                    'message' => 'Successfully uploaded!',
                    'filePath' => $filePath,
                ], 200);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload failed',
                    'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
                ], 422);
            }
        }

        if ($request->hasFile('file')) {
            $request->validate(['file' => 'required|image|max:51200']);

            try {
                $file = $request->file('file');
                $subfolder = $request->input('folder');
                $dir = $subfolder
                    ? "/filipinohomes-new/" . trim($subfolder, '/')
                    : "/filipinohomes-new";
                $filePath = $this->handleS3Upload($file, $dir);

                return response()->json([
                    'success' => true,
                    'message' => 'Successfully uploaded!',
                    'filePath' => $filePath,
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload failed',
                    'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
                ], 500);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed uploading file.',
            'filePath' => null,
        ]);
    }

    public function handleS3Upload($file, $dir)
    {
        return $this->uploadBytes(file_get_contents($file->getRealPath()), $dir);
    }

    /**
     * Download an image from a remote URL and re-host it through the same
     * compress + S3 pipeline as a direct upload. Throws on unreachable /
     * empty sources so callers can drop dead URLs.
     */
    public function uploadFromUrl(string $url, ?string $dir = null): string
    {
        $dir = $dir ?: "/filipinohomes-new";

        $resp = Http::timeout(30)->retry(2, 1000)->get($url);
        if (!$resp->ok()) {
            throw new \RuntimeException("GET {$resp->status()} for {$url}");
        }
        $bytes = $resp->body();
        if (strlen($bytes) === 0) {
            throw new \RuntimeException("empty body for {$url}");
        }

        return $this->uploadBytes($bytes, $dir);
    }

    /** Compress raw image bytes to WebP ≤ 50 KB, store on s3, return the URL. */
    public function uploadBytes(string $bytes, string $dir): string
    {
        $fileName = $dir . "/" . Str::uuid() . ".webp";

        $manager = new ImageManager(new Driver());
        $image = $manager->read($bytes)->scaleDown(width: 1200);

        $targetBytes = 50 * 1024;
        $q = 92;
        $encoded = '';
        do {
            $encoded = (string) $image->toWebp($q);
            if (strlen($encoded) <= $targetBytes || $q <= 4) break;
            $q -= 4;
        } while (true);

        Storage::disk('s3')->put($fileName, $encoded, 'public');

        return config('filesystems.disks.s3.url') . $fileName;
    }
}
