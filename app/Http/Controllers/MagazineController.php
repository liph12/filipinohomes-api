<?php

namespace App\Http\Controllers;

use App\Models\Magazine;
use Illuminate\Http\Request;
use App\Http\Resources\MagazineResourceCollection;
use App\Http\Resources\MagazineResource;

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
        return new MagazineResource(
            Magazine::findOrFail($id)
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'publish_date' => 'required|date',
            'cover_photo.*' => 'nullable|string|url',
            'pdf_file.*' => 'nullable|string|url',
        ]);

        $magazine = Magazine::create([
            'title' => $request->title,
            'description' => $request->description,
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
            'description',
            'publish_date',
            'cover_photo',
            'pdf_file'

        ]));

        return new MagazineResource($magazine);
    }
}