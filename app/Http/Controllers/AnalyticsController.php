<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsChatService;
use App\Services\Google\GaReportService;
use App\Services\Reports\WebsiteAnalyticsReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Website Analytics (GA4) — admin dashboard endpoints.
 *
 * All routes sit inside the RoleMiddleware:admin group. Data comes from the
 * GA4 Data API via GaReportService (server-cached: report 10 min, realtime
 * 45 s) so the dashboard can poll without burning the GA quota.
 */
class AnalyticsController extends Controller
{
    public function __construct(private GaReportService $reports)
    {
    }

    /** Connection state for the UI's "connect Google Analytics" empty state. */
    public function status(): JsonResponse
    {
        return response()->json(['configured' => $this->reports->configured()]);
    }

    /** Full website report for a date range (defaults: last 28 days, Manila). */
    public function website(Request $request): JsonResponse
    {
        if (! $this->reports->configured()) {
            return response()->json(['configured' => false], 503);
        }

        $validated = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $today = Carbon::now('Asia/Manila')->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : $today;
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(27);

        // Guardrails: ordered range, max 400 days, never beyond today.
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $to = $to->min($today);
        $from = $from->max($to->copy()->subDays(399));

        return response()->json([
            'configured' => true,
            ...$this->reports->websiteReport($from->toDateString(), $to->toDateString()),
        ]);
    }

    /** Active users right now (+ the pages they're on). */
    public function realtime(): JsonResponse
    {
        if (! $this->reports->configured()) {
            return response()->json(['configured' => false], 503);
        }

        return response()->json([
            'configured' => true,
            ...$this->reports->realtime(),
        ]);
    }

    /**
     * FH Analytics Assistant — OpenAI tool loop over the GA/GSC data layer.
     * Returns the model's plain-text reply plus the raw tool payloads the
     * dashboard charts from (numbers never come from model text).
     */
    public function chat(Request $request, AnalyticsChatService $assistant): JsonResponse
    {
        if (! $assistant->configured()) {
            return response()->json([
                'message' => 'The assistant needs Google Analytics and an OpenAI key configured on the server.',
            ], 503);
        }

        $validated = $request->validate([
            'messages' => 'required|array|min:1|max:16',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
        ]);

        return response()->json($assistant->chat($validated['messages']));
    }

    /** Daily-report configuration (settings KV — admin-managed, not env). */
    public function reportSettings(WebsiteAnalyticsReportService $reportService): JsonResponse
    {
        return response()->json($reportService->settings());
    }

    public function updateReportSettings(Request $request, WebsiteAnalyticsReportService $reportService): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'recipients' => 'present|array|max:30',
            'recipients.*' => 'email:rfc',
            'send_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'include_ai_summary' => 'required|boolean',
        ]);

        return response()->json($reportService->saveSettings($validated));
    }

    /**
     * "Send test to me" — one-off report to the CALLING admin only (never an
     * arbitrary address: the button can't be used to spam third parties).
     */
    public function sendTestReport(Request $request, WebsiteAnalyticsReportService $reportService): JsonResponse
    {
        if (! $reportService->configured()) {
            return response()->json(['message' => 'Google Analytics is not configured on the server.'], 503);
        }

        $email = $request->user()?->email;
        if (! $email) {
            return response()->json(['message' => 'Your account has no email address.'], 422);
        }

        $exit = Artisan::call('reports:send-website-analytics', ['email' => $email]);

        return $exit === 0
            ? response()->json(['message' => "Test report sent to {$email}."])
            : response()->json(['message' => 'Send failed — check the server logs.'], 500);
    }
}
