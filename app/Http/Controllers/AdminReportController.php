<?php

namespace App\Http\Controllers;

use App\Mail\AdminActivityReportMailer;
use App\Services\Reports\AdminActivityReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-triggered sends of the boss activity digest. One button in System
 * Users fires this at a chosen user so the report can be tested against
 * PRODUCTION data/SMTP before any schedule exists. Synchronous send — the
 * admin is watching and wants the success/failure right now, and it is one
 * email, not a campaign.
 */
class AdminReportController extends Controller
{
    public function sendActivityReport(Request $request, AdminActivityReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'name' => 'sometimes|nullable|string|max:120',
        ]);

        $today = now()->toDateString();

        try {
            $report = $reports->build($today, $today);

            Mail::to($data['email'])->send(new AdminActivityReportMailer(
                report: $report,
                periodLabel: now()->format('M j, Y'),
                recipientName: trim((string) ($data['name'] ?? '')) ?: 'Boss',
            ));
        } catch (\Throwable $e) {
            Log::warning('Activity report send failed', [
                'to' => $data['email'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Send failed: '.$e->getMessage()], 502);
        }

        Log::info('Activity report sent', ['to' => $data['email'], 'by' => $request->user()?->id]);

        return response()->json(['message' => "Today's activity report was sent to {$data['email']}."]);
    }
}
