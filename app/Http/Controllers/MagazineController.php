<?php

namespace App\Http\Controllers;

use App\Models\Magazine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        $disk = Storage::disk('local');
        $cachePath = "magazine-pdfs/{$magazine->id}.pdf";

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'Access-Control-Allow-Origin' => '*',
            // Browser + intermediary CDN cache for a day. Subsequent
            // visits to the same magazine never re-enter PHP at all
            // once the CDN warms (configure a Cloudflare cache rule
            // for /api/magazines/*/pdf to actually realise this — the
            // header alone isn't enough, CF treats unknown
            // content-types as DYNAMIC by default).
            'Cache-Control' => 'public, max-age=86400',
        ];

        // ───────────────── Cache hit ─────────────────
        // Local disk cache is fresh when its mtime is >= the
        // magazine's updated_at. Any admin edit (new pdf_file URL,
        // title rename, etc.) bumps updated_at, automatically
        // invalidating the cached binary on the next request. Served
        // via Storage::response() which uses Symfony
        // BinaryFileResponse + sendfile when the SAPI supports it —
        // PHP never touches the file body.
        if ($disk->exists($cachePath)
            && $disk->lastModified($cachePath) >= $magazine->updated_at->timestamp
        ) {
            return $disk->response($cachePath, "{$magazine->slug}.pdf", $headers);
        }

        // ──────────────── Cache miss ────────────────
        // Fetch from the upstream URL once and persist to local disk
        // before serving. Streamed in 8KB chunks so peak memory
        // stays in the kB range (see incident 2026-05-28,
        // "Allowed memory size of 134217728 bytes exhausted" — the
        // pre-stream implementation buffered the whole PDF body in
        // memory). Written to a temp path first and atomically
        // renamed into place so a failed mid-download never leaves a
        // truncated cache file behind.
        try {
            $upstream = Http::withOptions([
                'stream' => true,
                'connect_timeout' => 10,
                // No total-request cap — large PDFs take time to
                // transit from S3 and we don't want to artificially
                // abort partway through.
                'timeout' => 0,
            ])->get($pdfUrl);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Failed to fetch PDF from storage.');
        }

        if (!$upstream->successful()) {
            abort(502, 'Failed to fetch PDF from storage.');
        }

        $body = $upstream->toPsrResponse()->getBody();

        $tempPath = $cachePath . '.tmp.' . bin2hex(random_bytes(4));
        $tempFullPath = $disk->path($tempPath);

        $tempDir = dirname($tempFullPath);
        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            report(new \RuntimeException("Cannot create cache dir: {$tempDir}"));
            abort(500, 'Failed to cache PDF.');
        }

        $out = fopen($tempFullPath, 'wb');
        if ($out === false) {
            report(new \RuntimeException("Cannot open cache temp file: {$tempFullPath}"));
            abort(500, 'Failed to cache PDF.');
        }

        try {
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
            $body->close();
        }

        $disk->move($tempPath, $cachePath);

        return $disk->response($cachePath, "{$magazine->slug}.pdf", $headers);
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