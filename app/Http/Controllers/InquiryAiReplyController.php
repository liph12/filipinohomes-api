<?php

namespace App\Http\Controllers;

use App\Services\Inquiry\InquiryAiReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin settings for the Inquiry Filipinohomes Assistant — the System Settings card.
 * Same shape as InquiryAutoApprovalController (a JSON blob in the settings KV
 * table behind a service). See App\Services\Inquiry\InquiryAiReplyService.
 */
class InquiryAiReplyController extends Controller
{
    public function show(InquiryAiReplyService $service): JsonResponse
    {
        return response()->json($this->state($service));
    }

    public function update(Request $request, InquiryAiReplyService $service): JsonResponse
    {
        $service->saveSettings($request->validate($this->rules()));

        return response()->json($this->state($service));
    }

    /**
     * "Preview" in the dialog: the exact messages the assistant would send for
     * one inquiry (default: the latest accepted one), using the POSTED,
     * not-yet-saved settings. Nothing is posted.
     */
    public function preview(Request $request, InquiryAiReplyService $service): JsonResponse
    {
        $validated = $request->validate([
            ...$this->rules(partial: true),
            'conversation_id' => 'sometimes|nullable|integer',
        ]);
        $conversationId = $validated['conversation_id'] ?? null;
        unset($validated['conversation_id']);
        $settings = [...$service->settings(), ...$validated];

        $preview = $service->preview($conversationId ? (int) $conversationId : null, $settings);

        return $preview
            ? response()->json($preview)
            : response()->json(['message' => 'No approved inquiry to preview yet.'], 404);
    }

    private function state(InquiryAiReplyService $service): array
    {
        $s = $service->settings();

        return [
            'settings' => $s,
            'summary' => $service->summary($s),
            'office_hours' => $service->officeHoursLabel($s),
            'office_open' => $service->isOfficeOpen($s, Carbon::now()),
            'sent_today' => $service->sentToday(),
            'last_run' => $service->lastRun(),
            'recent' => $service->recent(),
        ];
    }

    private function rules(bool $partial = false): array
    {
        $p = $partial ? 'sometimes' : 'required';
        $time = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

        return [
            'enabled' => "{$p}|boolean",
            'ack_enabled' => "{$p}|boolean",
            'ack_delay_minutes' => "{$p}|integer|min:0|max:240",
            'followup_enabled' => "{$p}|boolean",
            'escalation_enabled' => "{$p}|boolean",
            'escalation_after_minutes' => "{$p}|integer|min:60|max:2880",
            'office_days' => "{$p}|array|min:1|max:7",
            'office_days.*' => 'integer|min:0|max:6',
            'office_start' => [$p, $time],
            'office_end' => [$p, $time],
            'max_age_hours' => "{$p}|integer|min:1|max:336",
            'daily_cap' => "{$p}|integer|min:1|max:2000",
            // May be left empty (that line is then left out of the message).
            'contact_mobile' => 'sometimes|nullable|string|max:40',
            'contact_whatsapp' => 'sometimes|nullable|string|max:40',
            'inactive_agent_enabled' => 'sometimes|boolean',
            'inactive_agent_days' => 'sometimes|integer|min:1|max:365',
            'direct_enabled' => 'sometimes|boolean',
        ];
    }
}
