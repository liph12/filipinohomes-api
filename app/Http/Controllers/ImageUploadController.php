<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class ImageUploadController extends Controller
{

    public function upload(Request $request)
    {
        if($request->hasFile('file')){
            
            $request->validate([
                'file' => 'required|image|max:20120'
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

    public function handleS3Upload($file, $dir)
    {
        $fileName = $dir . "/" . Str::uuid() . "." . $file->getClientOriginalExtension();
        Storage::disk('s3')->put($fileName, file_get_contents($file), 'public');

        return env("AWS_URL") . $fileName;
    }
// public function handleS3Upload($file, $dir)
// {
//     $ext      = strtolower($file->getClientOriginalExtension());
//     $fileName = $dir . "/" . Str::uuid() . "." . $ext;

//     $source = match($ext) {
//         'jpg', 'jpeg' => imagecreatefromjpeg($file->getRealPath()),
//         'png'         => imagecreatefrompng($file->getRealPath()),
//         'webp'        => imagecreatefromwebp($file->getRealPath()),
//         default       => null,
//     };

//     if ($source && imagesx($source) > 1920) {
//         $newH   = (int) (imagesy($source) * 1920 / imagesx($source));
//         $resized = imagecreatetruecolor(1920, $newH);
//         if ($ext === 'png') { imagealphablending($resized, false); imagesavealpha($resized, true); }
//         imagecopyresampled($resized, $source, 0, 0, 0, 0, 1920, $newH, imagesx($source), imagesy($source));
//         imagedestroy($source);
//         $source = $resized;
//     }

//     if ($source) {
//         ob_start();
//         match($ext) {
//             'jpg', 'jpeg' => imagejpeg($source, null, 92),
//             'png'         => imagepng($source, null, 6),
//             'webp'        => imagewebp($source, null, 92),
//         };
//         Storage::disk('s3')->put($fileName, ob_get_clean(), 'public');
//         imagedestroy($source);
//     } else {
//         Storage::disk('s3')->put($fileName, file_get_contents($file), 'public');
//     }

//     return env("AWS_URL") . $fileName;
// }
}