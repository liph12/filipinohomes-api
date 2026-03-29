<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    public function uploadFile(Request $request)
    {
        set_time_limit(900);
        if ($request->hasFile('file')) {

            $request->validate([
                'file' => 'required|file|mimes:pdf|max:102400',
            ]);

            try {
                $file = $request->file('file');
                $filePath = $this->handleS3Upload($file, "/magazines/pdfs");

                return response()->json([
                    'success' => true,
                    'message' => 'PDF uploaded successfully!',
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
            'message' => 'No file uploaded.',
            'filePath' => null,
        ]);
    }

    private function handleS3Upload($file, $dir)
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $sanitizedName = Str::slug($originalName);
        $extension = $file->getClientOriginalExtension();

        $fileName = $dir . "/" . $sanitizedName . "-" . Str::random(8) . "." . $extension;
        Storage::disk('s3')->put($fileName, file_get_contents($file), 'public');

        return env("AWS_URL") . $fileName;
    }
}