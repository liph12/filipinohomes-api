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
                'file' => 'required|image|max:20120'
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
        $encoded = $manager->read($file->getRealPath())
            ->scaleDown(width: 1920)
            ->toWebp(quality: 85);

        Storage::disk('s3')->put($fileName, (string) $encoded, 'public');

        return env("AWS_URL") . $fileName;
    }
}