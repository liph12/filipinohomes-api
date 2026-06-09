<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageUploadController extends Controller
{
    public function upload(Request $request)
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|image|max:51200'
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
        // GIFs bypass the Intervention/Image pipeline. GD's GIF
        // reader only decodes the first frame, so re-encoding to
        // WebP silently flattens the animation. The /admin/ads
        // upload path explicitly accepts GIFs as ad creatives —
        // killing the animation defeats the purpose. Store the
        // original bytes as .gif so the animation survives. The
        // 50 MB validation cap still applies.
        if ($file->getMimeType() === 'image/gif') {
            $fileName = $dir . "/" . Str::uuid() . ".gif";
            Storage::disk('s3')->put(
                $fileName,
                file_get_contents($file->getRealPath()),
                'public'
            );
            return config('filesystems.disks.s3.url') . $fileName;
        }

        $fileName = $dir . "/" . Str::uuid() . ".webp";

        $manager = new ImageManager(new Driver());
        $image = $manager->read($file->getRealPath())->scaleDown(width: 1200);

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

    // ATS upload: keep originals ≤ 5 MB, compress larger files to JPEG ≤ 5 MB
    // at full resolution. Documents need legible detail, so we never downscale.
    public function uploadAts(Request $request)
    {
        $request->validate(['file' => 'required|image|max:51200']);

        $file = $request->file('file');
        $dir = "/filipinohomes-new/" . trim($request->input('folder', 'ats'), '/');
        $threshold = 5 * 1024 * 1024;

        if ($file->getSize() <= $threshold) {
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $fileName = $dir . "/" . Str::uuid() . "." . $ext;
            Storage::disk('s3')->put($fileName, file_get_contents($file->getRealPath()), 'public');
        } else {
            $image = (new ImageManager(new Driver()))->read($file->getRealPath());
            $q = 92;
            do {
                $encoded = (string) $image->toJpeg($q);
                if (strlen($encoded) <= $threshold || $q <= 30) break;
                $q -= 4;
            } while (true);
            $fileName = $dir . "/" . Str::uuid() . ".jpg";
            Storage::disk('s3')->put($fileName, $encoded, 'public');
        }

        return response()->json([
            'success' => true,
            'filePath' => config('filesystems.disks.s3.url') . $fileName,
        ]);
    }
}