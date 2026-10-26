<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "What people ask ASA" — the admin read over asa_questions: volume, intents, places, outcomes,
 * the questions ASA could not answer, and how long each kind of answer takes.
 */
class AsaInsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->input('days', 30)));
        $since = now()->subDays($days);
        $base = fn () => DB::table('asa_questions')->where('created_at', '>=', $since);

        $total = (int) $base()->count();
        $byOutcome = $base()->select('outcome', DB::raw('COUNT(*) as n'))->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n) => (int) $n)->all();
        $byIntent = $base()->select('intent', DB::raw('COUNT(*) as n'), DB::raw('AVG(ms) as avg_ms'), DB::raw("SUM(CASE WHEN outcome <> 'answered' THEN 1 ELSE 0 END) as unanswered"))
            ->groupBy('intent')->orderByDesc('n')->get()
            ->map(fn ($r) => ['intent' => $r->intent, 'n' => (int) $r->n, 'avg_ms' => $r->avg_ms === null ? null : (int) round($r->avg_ms), 'unanswered' => (int) $r->unanswered])->all();
        $byPlace = $base()->whereNotNull('place')->select('place', DB::raw('COUNT(*) as n'), DB::raw("SUM(CASE WHEN outcome <> 'answered' THEN 1 ELSE 0 END) as unanswered"))
            ->groupBy('place')->orderByDesc('n')->limit(15)->get()
            ->map(fn ($r) => ['place' => $r->place, 'n' => (int) $r->n, 'unanswered' => (int) $r->unanswered])->all();
        $unanswered = $base()->where('outcome', '<>', 'answered')
            ->select(DB::raw('LOWER(TRIM(question)) as q'), 'intent', 'outcome', DB::raw('COUNT(*) as n'), DB::raw('MAX(created_at) as last_at'), DB::raw('MAX(place) as place'))
            ->groupBy('q', 'intent', 'outcome')->orderByDesc('n')->orderByDesc('last_at')->limit(60)->get()
            ->map(fn ($r) => ['question' => $r->q, 'intent' => $r->intent, 'outcome' => $r->outcome, 'n' => (int) $r->n, 'last_at' => (string) $r->last_at, 'place' => $r->place])->all();
        $top = $base()->select(DB::raw('LOWER(TRIM(question)) as q'), DB::raw('COUNT(*) as n'), DB::raw('MAX(intent) as intent'))
            ->groupBy('q')->orderByDesc('n')->limit(25)->get()
            ->map(fn ($r) => ['question' => $r->q, 'n' => (int) $r->n, 'intent' => $r->intent])->all();
        $perDay = $base()->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as n'), DB::raw("SUM(CASE WHEN outcome <> 'answered' THEN 1 ELSE 0 END) as unanswered"))
            ->groupBy('d')->orderBy('d')->get()
            ->map(fn ($r) => ['date' => (string) $r->d, 'n' => (int) $r->n, 'unanswered' => (int) $r->unanswered])->all();
        $devices = (int) $base()->whereNotNull('device_id')->distinct('device_id')->count('device_id');

        return response()->json([
            'days' => $days,
            'totals' => ['questions' => $total, 'people' => $devices, 'answered' => (int) ($byOutcome['answered'] ?? 0), 'no_results' => (int) ($byOutcome['no_results'] ?? 0), 'no_data' => (int) ($byOutcome['no_data'] ?? 0), 'error' => (int) ($byOutcome['error'] ?? 0)],
            'by_intent' => $byIntent,
            'by_place' => $byPlace,
            'unanswered' => $unanswered,
            'top_questions' => $top,
            'per_day' => $perDay,
        ]);
    }
}
