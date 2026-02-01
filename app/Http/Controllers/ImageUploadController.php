<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
class ImageUploadController extends Controller
{
    /**
     * Upload multiple images
     * 
     * POST /api/upload
     * 
     * Accepts single or multiple files
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        try {
            // Check if single file or multiple files
            $hasMultiple = $request->hasFile('files');
            $hasSingle = $request->hasFile('file');

            if (!$hasMultiple && !$hasSingle) {
                return response()->json([
                    'success' => false,
                    'message' => 'No files uploaded. Use "file" for single upload or "files[]" for multiple uploads.'
                ], 400);
            }

            // Determine validation rules based on input
            if ($hasMultiple) {
                $validator = Validator::make($request->all(), [
                    'files' => 'required|array|max:20', // Max 20 files at once
                    'files.*' => [
                        'required',
                        'file',
                        'image',
                        'mimes:jpeg,png,jpg,gif,webp',
                        'max:10240' // 10MB max per file
                    ]
                ]);
            } else {
                $validator = Validator::make($request->all(), [
                    'file' => [
                        'required',
                        'file',
                        'image',
                        'mimes:jpeg,png,jpg,gif,webp',
                        'max:10240'
                    ]
                ]);
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $uploadedFiles = [];
            $errors = [];

            // Get files array (handle both single and multiple)
            $files = $hasMultiple 
                ? $request->file('files') 
                : [$request->file('file')];

            foreach ($files as $index => $file) {
                try {
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();
                    $filename = time() . '_' . Str::random(10) . '.' . $extension;
                    
                    // Store in public/storage/listings folder
                    $path = $file->storeAs('listings', $filename, 'public');
                    
                    // Get the full URL
                    $url = url(Storage::url($path));
                    
                    $uploadedFiles[] = [
                        'url' => $url,
                        'path' => $path,
                        'filename' => $filename,
                        'original_name' => $originalName,
                        'size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ];
                    
                    // Small delay to ensure unique timestamps
                    usleep(100);
                    
                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'filename' => $file->getClientOriginalName(),
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Return appropriate response
            if (count($uploadedFiles) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'All uploads failed',
                    'errors' => $errors
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => count($uploadedFiles) . ' file(s) uploaded successfully',
                'files' => $uploadedFiles,
                'errors' => count($errors) > 0 ? $errors : null,
                'total_uploaded' => count($uploadedFiles),
                'total_failed' => count($errors),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('File upload error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Upload failed',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Delete an uploaded image
     * 
     * DELETE /api/upload/{filename}
     * 
     * @param string $filename
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete($filename)
    {
        try {
            $path = 'listings/' . $filename;
            
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
                
                return response()->json([
                    'success' => true,
                    'message' => 'File deleted successfully'
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);

        } catch (\Exception $e) {
            \Log::error('File deletion error:', [
                'filename' => $filename,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete file'
            ], 500);
        }
    }
}