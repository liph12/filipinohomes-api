<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use App\Services\ImageVariantService;
use Illuminate\Support\Facades\Log;

class ImageUploadController extends Controller
{
    public function upload(Request $request)
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|image|mimes:jpeg,jpg,png,webp|max:51200'
            ]);

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
                    'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
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
        $fileName = $dir . "/" . Str::uuid() . ".webp";

        $manager = new ImageManager(new Driver());
        $image = $manager->read($file->getRealPath())->scaleDown(width: 1200);

        $encoded = $this->encodeUnderTarget(
            fn ($q) => (string) $image->toWebp($q),
            50 * 1024,
            4,
            92,
        );

        Storage::disk('s3')->put($fileName, $encoded, 'public');

        // Responsive width-variants from the ORIGINAL source bytes (highest
        // quality). Best-effort: a variant failure must never break the upload.
        try {
            app(ImageVariantService::class)->generateVariants(
                (string) file_get_contents($file->getRealPath()),
                $fileName,
            );
        } catch (\Throwable $e) {
            Log::warning('upload variant generation failed', [
                'key' => $fileName,
                'error' => $e->getMessage(),
            ]);
        }

        return config('filesystems.disks.s3.url') . $fileName;
    }

    /**
     * High-quality uploader for the agent PAGE BUILDER (banner, about photo,
     * gallery, certs, awards). Separate from /upload on purpose: that route's
     * 50KB/1200px WebP budget is tuned for logos and small page assets, and
     * hero photography through it turns to mush. Here: 2560px max, one fixed
     * WebP encode at quality 88, NO byte target — photographic quality, still
     * web-sane (typically 200–800KB). Responsive variants are generated from
     * the original bytes exactly like /upload, so srcset consumers keep
     * getting sized files.
     */
    public function uploadHq(Request $request)
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,jpg,png,webp|max:51200',
        ]);

        try {
            $file = $request->file('file');
            $subfolder = $request->input('folder');
            $dir = $subfolder
                ? "/filipinohomes-new/" . trim($subfolder, '/')
                : "/filipinohomes-new";

            $fileName = $dir . "/" . Str::uuid() . ".webp";

            $manager = new ImageManager(new Driver());
            // scaleDown never upscales; both dimensions capped so portraits
            // (about-me photos) are bounded too.
            $image = $manager->read($file->getRealPath())
                ->scaleDown(width: 2560, height: 2560);
            $encoded = (string) $image->toWebp(88);

            Storage::disk('s3')->put($fileName, $encoded, 'public');

            try {
                app(ImageVariantService::class)->generateVariants(
                    (string) file_get_contents($file->getRealPath()),
                    $fileName,
                );
            } catch (\Throwable $e) {
                Log::warning('upload-hq variant generation failed', [
                    'key' => $fileName,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Successfully uploaded!',
                'filePath' => config('filesystems.disks.s3.url') . $fileName,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload failed',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    private function encodeUnderTarget(callable $encode, int $targetBytes, int $minQ, int $maxQ): string
    {
        $best = $encode($maxQ);
        if (strlen($best) <= $targetBytes) {
            return $best;
        }

        $lo   = $minQ;
        $hi   = $maxQ - 1;
        $best = $encode($minQ);
        while ($lo <= $hi) {
            $mid       = intdiv($lo + $hi, 2);
            $candidate = $encode($mid);
            if (strlen($candidate) <= $targetBytes) {
                $best = $candidate; 
                $lo   = $mid + 1;
            } else {
                $hi = $mid - 1;   
            }
        }

        return $best;
    }

    public function uploadAts(Request $request)
    {
        $request->validate(['file' => 'required|image|max:51200']);

        $file = $request->file('file');
        $dir = "/filipinohomes-new/" . trim($request->input('folder', 'ats'), '/');

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $fileName = $dir . "/" . Str::uuid() . "." . $ext;
        Storage::disk('s3')->put($fileName, file_get_contents($file->getRealPath()), 'public');

        return response()->json([
            'success' => true,
            'filePath' => config('filesystems.disks.s3.url') . $fileName,
        ]);
    }
}