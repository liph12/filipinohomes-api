<?php

namespace App\Http\Controllers;

use App\Services\Inquiry\InquiryAutoApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Admin settings surface for the automatic inquiry approver — the System
 * Settings "Automatic Approver" card. Mirrors
 * AnalyticsController::reportSettings/updateReportSettings (a JSON blob in
 * the `settings` KV table behind a service); see
 * App\Services\Inquiry\InquiryAutoApprovalService for the shape + window math
 * and App\Console\Commands\AutoApproveInquiries for the scheduled consumer.
 */
class InquiryAutoApprovalController extends Controller
{
    public function show(InquiryAutoApprovalService $approval): JsonResponse
    {
        return response()->json($this->state($approval));
    }

    public function update(Request $request, InquiryAutoApprovalService $approval): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $approval->saveSettings($validated);

        return response()->json($this->state($approval));
    }

    /**
     * "Check" button in the settings dialog — reports how many pending
     * inquiries would be approved right now under the POSTED (not yet
     * saved) settings, so the admin can tune the window before committing
     * it. Falls back to the currently saved settings for any field left out
     * of the body. Nothing is written.
     */
    public function preview(Request $request, InquiryAutoApprovalService $approval): JsonResponse
    {
        $validated = $request->validate($this->rules(partial: true));
        $settings = [...$approval->settings(), ...$validated];
        $now = Carbon::now(InquiryAutoApprovalService::TIMEZONE);

        return response()->json([
            'eligible_count' => $approval->eligibleCount($settings),
            'window_open' => $approval->isWindowOpen($settings, $now),
            'pending_total' => $approval->pendingTotal(),
        ]);
    }

    private function state(InquiryAutoApprovalService $approval): array
    {
        $settings = $approval->settings();
        $now = Carbon::now(InquiryAutoApprovalService::TIMEZONE);

        return [
            'settings' => $settings,
            'summary' => $approval->summary($settings),
            'window_open' => $approval->isWindowOpen($settings, $now),
            'pending_total' => $approval->pendingTotal(),
            'eligible_count' => $approval->eligibleCount($settings),
            'last_run' => $approval->lastRun(),
        ];
    }

    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'enabled' => "{$presence}|boolean",
            'days' => [$presence, Rule::in(['weekdays', 'weekends', 'all', 'custom'])],
            'custom_days' => "{$presence}|array|max:7",
            'custom_days.*' => 'integer|min:0|max:6',
            'restrict_hours' => "{$presence}|boolean",
            'start_time' => [$presence, 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => [$presence, 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'delay_minutes' => "{$presence}|integer|min:0|max:1440",
            'max_age_hours' => "{$presence}|integer|min:1|max:720",
            'max_per_run' => "{$presence}|integer|min:1|max:200",
        ];
    }
}
