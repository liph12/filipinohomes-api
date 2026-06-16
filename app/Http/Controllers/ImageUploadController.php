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

        return config('filesystems.disks.s3.url') . $fileName;
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