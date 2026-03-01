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
        if($request->hasFile('file')){
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

        return response()->json([
            'success' => false,
            'message' => 'Failed uploading file.',
            'filePath' => null,
        ]);
    }

   public function handleS3Upload($file, $dir)
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension    = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $allowedExtensions)) {
            throw new \InvalidArgumentException("File type .{$extension} is not allowed.");
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException("File size exceeds the 5MB limit.");
        }

        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $originalName);
        $safeName = preg_replace('/-+/', '-', $safeName);
        $safeName = trim($safeName, '-') ?: 'file';

        // Unique path — always store as .webp
        $uniqueFolder = time() . '_' . Str::uuid();
        $fileName = $dir . '/' . $uniqueFolder . '/' . $safeName . '.webp';

        // Convert to WebP using GD driver
        $manager  = new ImageManager(new Driver());
        $webpData = $manager->read($file->getRealPath())
            ->toWebp(quality: 85)
            ->toString();

        Storage::disk('s3')->put($fileName, $webpData, [
            'visibility'  => 'public', // 'ACL'         => 'public-read',
            'ContentType' => 'image/webp',
        ]);

        return config('filesystems.disks.s3.url') . '/' . ltrim($fileName, '/');
    }
}