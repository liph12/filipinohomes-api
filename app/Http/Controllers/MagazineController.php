<?php

namespace App\Http\Controllers;

use App\Models\Magazine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Resources\MagazineResourceCollection;
use App\Http\Resources\MagazineResource;
use Illuminate\Support\Str;
class MagazineController extends Controller
{
    public function index()
    {
        return new MagazineResourceCollection(
            Magazine::latest()->paginate(10)
        );
    }

    public function show($id)
    {
        $magazine = is_numeric($id)
            ? Magazine::findOrFail($id)
            : Magazine::where('slug', $id)->firstOrFail();

        return new MagazineResource($magazine);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'slug' => 'nullable|string',
            'publish_date' => 'required|date',
            'cover_photo.*' => 'nullable|string|url',
            'pdf_file.*' => 'nullable|string|url',
        ]);

        $magazine = Magazine::create([
            'title' => $request->title,
            'description' => $request->description,
            'slug' => $request->slug ?? Str::slug($request->title),
            'publish_date' => $request->publish_date,
            'cover_photo' => $request->cover_photo,
            'pdf_file' => $request->pdf_file,
        ]);

        return new MagazineResource($magazine);
    }

    public function update(Request $request, $id)
    {
        $magazine = Magazine::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'publish_date' => 'sometimes|date',
            'cover_photo.*' => 'nullable|string|url',
            'pdf_file.*' => 'nullable|string|url',
        ]);

        $magazine->update($request->only([
            'title',
            'slug',
            'description',
            'publish_date',
            'cover_photo',
            'pdf_file',
        ]));

        return new MagazineResource($magazine);
    }

    public function streamPdf($id)
    {
        $magazine = is_numeric($id)
            ? Magazine::findOrFail($id)
            : Magazine::where('slug', $id)->firstOrFail();

        $pdfUrl = $magazine->pdf_file[0] ?? null;

        if (!$pdfUrl) {
            abort(404, 'No PDF available for this magazine.');
        }

        $response = Http::withOptions(['stream' => true])->get($pdfUrl);

        if (!$response->successful()) {
            abort(502, 'Failed to fetch PDF from storage.');
        }

        return response($response->body(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}