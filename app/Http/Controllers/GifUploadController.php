<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * No-compression media uploader. Stores the ORIGINAL file as-is — no resize, no
 * WebP conversion — so animated GIFs keep their animation, photos keep full
 * quality, and VIDEO creatives are stored verbatim. Accepts images plus short
 * video formats. Used by the /admin/ads uploader.
 *
 * NOTE: the `image` rule is intentionally omitted — it restricts the upload to
 * image MIME types and would reject videos. Validation is by explicit `mimes`.
 */
class GifUploadController extends Controller
{
    public function upload(Request $request)
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|file|mimes:jpeg,jpg,png,webp,gif,mp4,webm,mov,m4v|max:51200',
            ]);

            try {
                $file = $request->file('file');
                $subfolder = $request->input('folder');
                $dir = $subfolder
                    ? "/filipinohomes-new/" . trim($subfolder, '/')
                    : "/filipinohomes-new";

                // Keep the original extension/bytes — store verbatim.
                $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
                $fileName = $dir . "/" . Str::uuid() . "." . $ext;
                Storage::disk('s3')->put($fileName, file_get_contents($file->getRealPath()), 'public');

                return response()->json([
                    'success' => true,
                    'message' => 'Successfully uploaded!',
                    'filePath' => config('filesystems.disks.s3.url') . $fileName,
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
}
