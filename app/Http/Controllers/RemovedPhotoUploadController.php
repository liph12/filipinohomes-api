<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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

    /**
     * Re-host many remote URLs at once. Downloads them CONCURRENTLY via
     * Http::pool — the main latency win over calling uploadFromUrl() in a loop,
     * where each image waited on the previous one's full download — then
     * compresses + stores each. Returns a map of sourceUrl => newUrl, with null
     * for any that failed to download or process (callers report/log those).
     *
     * @param  string[] $urls
     * @return array<string,?string>
     */
    public function uploadManyFromUrls(array $urls, ?string $dir = null): array
    {
        $dir  = $dir ?: "/filipinohomes-new";
        $urls = array_values(array_unique(array_filter(
            $urls,
            fn ($u) => is_string($u) && trim($u) !== '',
        )));
        if (empty($urls)) {
            return [];
        }

        // Fire all downloads in parallel; responses come back keyed by index.
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($url) => $pool->timeout(10)->get($url),
            $urls,
        ));

        $map = [];
        foreach ($urls as $i => $url) {
            $resp = $responses[$i] ?? null;
            try {
                // A failed connection comes back as an exception, not a Response.
                if (!$resp instanceof Response || !$resp->ok()) {
                    throw new \RuntimeException('download failed');
                }
                $bytes = $resp->body();
                if (strlen($bytes) === 0) {
                    throw new \RuntimeException('empty body');
                }
                $map[$url] = $this->uploadBytes($bytes, $dir);
            } catch (\Throwable $e) {
                $map[$url] = null;
            }
        }

        return $map;
    }

    /** Compress raw image bytes to WebP ≤ 50 KB, store on s3, return the URL. */
    public function uploadBytes(string $bytes, string $dir): string
    {
        $fileName = $dir . "/" . Str::uuid() . ".webp";

        $manager = new ImageManager(new Driver());
        $image = $manager->read($bytes)->scaleDown(width: 1200);

        $encoded = $this->encodeWebpUnderTarget($image, 50 * 1024);

        Storage::disk('s3')->put($fileName, $encoded, 'public');

        // Responsive width-variants from the source bytes (best-effort).
        try {
            app(\App\Services\ImageVariantService::class)->generateVariants($bytes, $fileName);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('recovery upload variant generation failed', [
                'key' => $fileName,
                'error' => $e->getMessage(),
            ]);
        }

        return config('filesystems.disks.s3.url') . $fileName;
    }

    /**
     * Encode to WebP at the highest quality that still fits under $targetBytes,
     * via a BINARY SEARCH over the quality range — ~7 encodes worst case versus
     * ~23 for the old linear q-=4 walk. Most images (already scaled to 1200px)
     * fit at top quality and return in a single encode. Falls back to the
     * lowest quality if nothing fits.
     */
    private function encodeWebpUnderTarget($image, int $targetBytes): string
    {
        // Try best quality first — the common case after the 1200px scale-down.
        $best = (string) $image->toWebp(92);
        if (strlen($best) <= $targetBytes) {
            return $best;
        }

        // Binary search for the highest quality whose output fits the target.
        $lo   = 4;
        $hi   = 88;
        $best = (string) $image->toWebp($lo); // smallest possible — guaranteed baseline
        while ($lo <= $hi) {
            $mid       = intdiv($lo + $hi, 2);
            $candidate = (string) $image->toWebp($mid);
            if (strlen($candidate) <= $targetBytes) {
                $best = $candidate; // fits — try pushing quality higher
                $lo   = $mid + 1;
            } else {
                $hi = $mid - 1;     // too big — drop quality
            }
        }

        return $best;
    }
}
