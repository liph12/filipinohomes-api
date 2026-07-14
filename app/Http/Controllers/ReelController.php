<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Services\AuditReelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reel Maker endpoints. The reel is generated client-side, so this only records
 * usage into the audit trail (see AuditReelService). Fire-and-forget from the UI.
 */
class ReelController extends Controller
{
    public function logEvent(Request $request, AuditReelService $audit): JsonResponse
    {
        $data = $request->validate([
            'slug'  => ['nullable', 'string', 'max:255'],
            'event' => ['required', 'string', 'in:' . implode(',', AuditReelService::EVENTS)],
            'meta'  => ['nullable', 'array'],
        ]);

        $listing = !empty($data['slug'])
            ? Listing::where('slug', $data['slug'])->first()
            : null;

        $audit->record($data['event'], $listing, $this->sanitizeMeta($data['meta'] ?? []));

        return response()->json(null, 204);
    }

    /**
     * Keep only known reel knobs, coerced to scalars/short strings, so a crafted
     * payload can't bloat the audit row's new_values.
     */
    private function sanitizeMeta(array $meta): array
    {
        $allowed = [
            'format', 'action', 'total_seconds', 'photo_count', 'size_bytes',
            'theme', 'badge', 'logo', 'music', 'transition', 'intro_s', 'outro_s',
        ];

        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $meta) || !is_scalar($meta[$key])) {
                continue;
            }
            $out[$key] = is_string($meta[$key]) ? mb_substr($meta[$key], 0, 64) : $meta[$key];
        }

        // Per-slide durations: numeric array, capped length.
        if (isset($meta['durations']) && is_array($meta['durations'])) {
            $out['durations'] = array_slice(
                array_map(fn ($n) => round((float) $n, 2), array_values($meta['durations'])),
                0,
                12,
            );
        }

        return $out;
    }
}
