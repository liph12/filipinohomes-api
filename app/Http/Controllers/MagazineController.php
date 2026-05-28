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
    public function years()
    {
        $years = Magazine::query()
            ->whereNotNull('publish_date')
            ->selectRaw('YEAR(publish_date) as year')
            ->groupBy('year')
            ->orderByDesc('year')
            ->pluck('year')
            ->filter()
            ->values();

        return response()->json($years, 200);
    }

    public function index(Request $request)
    {
        $query = Magazine::query();

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            if ($term !== '') {
                $query->where(function ($q) use ($term) {
                    $q->where('title', 'like', "%{$term}%")
                      ->orWhere('description', 'like', "%{$term}%");
                });
            }
        }

        if ($request->filled('year')) {
            $year = (int) $request->input('year');
            if ($year > 0) {
                $query->whereYear('publish_date', $year);
            }
            $items = $query->orderBy('publish_date', 'desc')->get();
            return MagazineResource::collection($items);
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 1) { $perPage = 10; }

        return new MagazineResourceCollection(
            $query->orderBy('publish_date', 'desc')->paginate($perPage)
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
            'slug' => 'sometimes|nullable|string|unique:magazines,slug,' . $magazine->id,
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

        // Stream the upstream PDF chunk-by-chunk straight to the
        // client instead of buffering it in memory. The previous
        // implementation called $response->body(), which read the
        // entire PDF into a PHP string before returning the
        // Symfony Response — fine for ~30MB issues but fatal for the
        // company-profile PDF that pushed past PHP's 128MB
        // memory_limit (incident 2026-05-28,
        // "Allowed memory size of 134217728 bytes exhausted").
        // Streaming keeps peak memory in the kB range regardless of
        // issue size.
        try {
            $upstream = Http::withOptions([
                'stream' => true,
                'connect_timeout' => 10,
                // No total-request cap — large PDFs take time to
                // transit and we don't want to artificially abort.
                'timeout' => 0,
            ])->get($pdfUrl);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Failed to fetch PDF from storage.');
        }

        if (!$upstream->successful()) {
            abort(502, 'Failed to fetch PDF from storage.');
        }

        $psr7 = $upstream->toPsrResponse();
        $body = $psr7->getBody();

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ];

        // Pass through Content-Length when upstream provides it so
        // the browser can show real download progress instead of an
        // indeterminate spinner.
        if ($psr7->hasHeader('Content-Length')) {
            $headers['Content-Length'] = $psr7->getHeaderLine('Content-Length');
        }

        return response()->stream(function () use ($body) {
            while (!$body->eof()) {
                echo $body->read(8192);
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }
            $body->close();
        }, 200, $headers);
    }

    public function destroy($id)
    {
        $magazine = is_numeric($id)
            ? Magazine::find($id)
            : Magazine::where('slug', $id)->first();

        if (! $magazine) {
            return response()->json([
                'message' => 'Magazine not found',
                'id' => is_numeric($id) ? (int) $id : $id,
            ], 404);
        }

        $magazine->delete();

        return response()->json([
            'message' => 'Magazine deleted',
            'id' => $magazine->id,
        ], 200);
    }
}