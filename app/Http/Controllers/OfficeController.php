<?php

namespace App\Http\Controllers;

use App\Http\Resources\OfficeResource;
use App\Http\Resources\OfficeResourceCollection;
use App\Models\Office;
use Illuminate\Http\Request;

class OfficeController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:1000',
        ]);

        $query = Office::query();

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('contact', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 12);

        return new OfficeResourceCollection(
            $query
                ->orderByRaw("
                    CASE
                        WHEN LOWER(COALESCE(title, '')) = 'headquarters' THEN 0
                        ELSE 1
                    END
                ")
                ->orderByRaw("
                    LOWER(
                        TRIM(
                            REGEXP_REPLACE(COALESCE(name, ''), '[[:space:]]+[0-9]+$', '')
                        )
                    )
                ")
                ->orderByRaw("
                    CASE
                        WHEN COALESCE(name, '') REGEXP '[0-9]+$'
                            THEN CAST(REGEXP_SUBSTR(name, '[0-9]+$') AS UNSIGNED)
                        ELSE 0
                    END
                ")
                ->orderBy('name')
                ->paginate($perPage)
                ->withQueryString()
        );
    }

    public function show(string $slug)
    {
        $office = Office::where('slug', $slug)->firstOrFail();

        return new OfficeResource($office);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:offices,slug',
            'title' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'photo' => 'nullable|array',
            'photo.*' => 'nullable|string',
            'geo_coordinates' => 'nullable|array:lat,lng',
        ]);

        $office = Office::create($data);

        return response()->json(new OfficeResource($office), 201);
    }

    public function update(Request $request, Office $office)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|unique:offices,slug,' . $office->id,
            'title' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'photo' => 'nullable|array',
            'photo.*' => 'nullable|string',
            'geo_coordinates' => 'nullable|array:lat,lng',
        ]);

        $office->update($data);

        return response()->json(new OfficeResource($office));
    }

    public function destroy($office)
    {
        $model = Office::find($office);
        if (! $model) {
            return response()->json([
                'message' => 'Office not found',
                'id' => is_numeric($office) ? (int) $office : $office,
            ], 404);
        }

        $model->delete();

        return response()->json([
            'message' => 'Office deleted',
            'id' => $model->id,
        ], 200);
    }
}
