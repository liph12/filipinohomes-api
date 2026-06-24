<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Serves administrative boundary polygons (city/municipality or barangay) that
 * intersect the current map viewport, as a simplified GeoJSON FeatureCollection,
 * for the admin all-listings map overlay. Geometry is SRID 0 (planar) throughout.
 */
class BoundaryController extends Controller
{
    private const CAP = 300;

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'level'   => 'nullable|in:city,barangay',
            'min_lat' => 'required|numeric',
            'max_lat' => 'required|numeric',
            'min_lng' => 'required|numeric',
            'max_lng' => 'required|numeric',
        ]);

        $level = $v['level'] ?? 'city';
        // Coarser simplification for cities, finer for barangays (degrees).
        $tol = $level === 'barangay' ? 0.0002 : 0.0008;

        $minLat = (float) $v['min_lat'];
        $maxLat = (float) $v['max_lat'];
        $minLng = (float) $v['min_lng'];
        $maxLng = (float) $v['max_lng'];

        // Viewport rectangle as SRID 0 WKT (lng lat order). %F is locale-independent.
        $envelope = sprintf(
            'POLYGON((%.8F %.8F, %.8F %.8F, %.8F %.8F, %.8F %.8F, %.8F %.8F))',
            $minLng, $minLat, $maxLng, $minLat, $maxLng, $maxLat, $minLng, $maxLat, $minLng, $minLat
        );

        $rows = DB::select(
            'SELECT id, name, level, city_id, barangay_id,
                    ST_AsGeoJSON(COALESCE(ST_Simplify(geom, ?), geom)) AS gj
             FROM boundaries
             WHERE level = ?
               AND ST_Intersects(geom, ST_GeomFromText(?, 0))
             LIMIT ' . (self::CAP + 1),
            [$tol, $level, $envelope]
        );

        $capped = count($rows) > self::CAP;
        if ($capped) {
            $rows = array_slice($rows, 0, self::CAP);
            Log::info('map-boundaries capped', ['level' => $level]);
        }

        $features = [];
        foreach ($rows as $r) {
            $geom = json_decode($r->gj, true);
            if (! is_array($geom)) {
                continue;
            }
            $features[] = [
                'type'       => 'Feature',
                'properties' => [
                    'id'          => (int) $r->id,
                    'name'        => $r->name,
                    'level'       => $r->level,
                    'city_id'     => $r->city_id !== null ? (int) $r->city_id : null,
                    'barangay_id' => $r->barangay_id !== null ? (int) $r->barangay_id : null,
                ],
                'geometry'   => $geom,
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
            'capped'   => $capped,
        ]);
    }
}
