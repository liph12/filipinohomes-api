<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppVersionResource;
use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppVersionController extends Controller
{
    /**
     * Public list for the web downloads page. Newest first; android only for now.
     */
    public function index(Request $request)
    {
        $versions = AppVersion::where('platform', $request->input('platform', 'android'))
            ->orderByDesc('is_latest')
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get();

        return AppVersionResource::collection($versions);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'version'      => 'required|string|max:32',
            'platform'     => 'nullable|string|max:16',
            'download_url' => 'required|url|max:2048',
            'notes'        => 'nullable|string',
            'is_latest'    => 'boolean',
            'released_at'  => 'nullable|date',
        ]);
        $validated['platform'] = $validated['platform'] ?? 'android';

        $version = DB::transaction(function () use ($validated) {
            $version = AppVersion::create($validated);
            if ($version->is_latest) {
                $this->clearOtherLatest($version);
            }
            return $version;
        });

        return new AppVersionResource($version);
    }

    public function update(Request $request, $id)
    {
        $version = AppVersion::findOrFail($id);

        $validated = $request->validate([
            'version'      => 'sometimes|string|max:32',
            'platform'     => 'sometimes|string|max:16',
            'download_url' => 'sometimes|url|max:2048',
            'notes'        => 'nullable|string',
            'is_latest'    => 'boolean',
            'released_at'  => 'nullable|date',
        ]);

        DB::transaction(function () use ($version, $validated) {
            $version->update($validated);
            if ($version->is_latest) {
                $this->clearOtherLatest($version);
            }
        });

        return new AppVersionResource($version);
    }

    public function destroy($id)
    {
        $version = AppVersion::findOrFail($id);
        $version->delete();

        return response()->json(['message' => 'App version deleted', 'id' => $version->id]);
    }

    /** Keep a single latest per platform — flip every other row off. */
    private function clearOtherLatest(AppVersion $version): void
    {
        AppVersion::where('platform', $version->platform)
            ->where('id', '!=', $version->id)
            ->where('is_latest', true)
            ->update(['is_latest' => false]);
    }
}
