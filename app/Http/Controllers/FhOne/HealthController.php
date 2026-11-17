<?php

namespace App\Http\Controllers\FhOne;

use App\Http\Controllers\Controller;
use App\Services\Reports\Executive\ReportRange;
use App\Support\FhOneKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /api/fh-one/v1/health — is FH up, does the key work, how long is it good for?
 * FH One's Admin › Connections page calls this. Behind VerifyFhOneKey, so a 200 proves the key.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = true;
        } catch (Throwable) {
            $database = false;
        }

        $lastDay = (string) $request->attributes->get('fhone.key_expires');

        return response()->json([
            'status' => $database ? 'ok' : 'degraded',
            'service' => 'filipino-homes',
            'version' => 'v1',
            // FH's clock, so FH One can notice if the two disagree.
            'time' => Carbon::now(ReportRange::TZ)->toIso8601String(),
            'database' => $database ? 'ok' : 'down',
            'key' => [
                'name' => $request->attributes->get('fhone.key'),
                'last_valid_day' => $lastDay,
                'days_left' => FhOneKeys::daysLeft($lastDay),
            ],
        ], $database ? 200 : 503);
    }
}
