<?php

namespace App\Http\Controllers;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Storage;

class ImageUploadController extends Controller
{

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ]);

        try {
            $file = $request->file('file');
            $filePath = $this->handleS3Upload($file, "/fh-new-listings");

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

    private function handleS3Upload($file, $dir)
    {
        $uniqueName = (string) Str::uuid();
        $fileName = $dir . '/' . $uniqueName . '.webp';

        // Convert to WebP using GD driver
        $manager  = new ImageManager(new Driver());
        $webpData = $manager->read($file->getRealPath())
            ->toWebp(quality: 85)
            ->toString();

        Storage::disk('s3')->put($fileName, $webpData, [
            'visibility'  => 'public',
            'ContentType' => 'image/webp',
        ]);

        return rtrim(config('filesystems.disks.s3.url'), '/') . '/' . ltrim($fileName, '/');
    }
}