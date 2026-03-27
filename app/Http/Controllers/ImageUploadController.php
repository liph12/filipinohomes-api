<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
class ImageUploadController extends Controller
{

    public function upload(Request $request)
    {
        if($request->hasFile('file')){
            
            $request->validate([
                'file' => 'required|image|max:5120'
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

        return response()->json([
            'success' => false,
            'message' => 'Failed uploading file.',
            'filePath' => null,
        ]);
    }

    // public function handleS3Upload($file, $dir)
    // {
    //     $fileName = $dir . "/" . Str::uuid() . "." . $file->getClientOriginalExtension();
    //     Storage::disk('s3')->put($fileName, file_get_contents($file), 'public');

    //     return env("AWS_URL") . $fileName;
    // }
    public function handleS3Upload($file, $dir)
    {
        $ext = $file->getClientOriginalExtension();
        $fileName = $dir . "/" . Str::uuid() . "." . $ext; // keep original format

        $image = Image::read($file)
            ->scaleDown(width: 1920)
            ->encode(quality: 92);    // near-lossless, keeps original format

        Storage::disk('s3')->put($fileName, $image, 'public');

        return env("AWS_URL") . $fileName;
    }
}