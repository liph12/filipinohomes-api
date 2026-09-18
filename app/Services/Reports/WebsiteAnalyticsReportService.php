<?php

namespace App\Services\Reports;

use App\Models\Setting;
use App\Services\Google\GaReportService;
use Illuminate\Support\Carbon;
use OpenAI;
use Throwable;

/**
 * The daily Website Analytics email — settings, assembly, and the optional
 * AI executive summary.
 *
 * Settings live in the `settings` KV table (admin-configurable from the
 * Website Analytics dashboard, NOT env): enabled flag, recipient list,
 * send time (Asia/Manila), and whether to include the AI summary. The
 * report itself is yesterday's GA report + a month-to-date report from
 * GaReportService (both server-cached there).
 *
 * The AI summary is strictly decorative narration: the prompt receives the
 * exact numbers and is told to only restate them — and any failure (no key,
 * quota, timeout) degrades to null so the email still sends.
 */
class WebsiteAnalyticsReportService
{
    public const SETTINGS_KEY = 'website_analytics_report';

    public const DEFAULTS = [
        'enabled' => false,
        'recipients' => [],
        'send_time' => '07:30',
        'include_ai_summary' => true,
    ];

    public function __construct(private GaReportService $ga)
    {
    }

    public function configured(): bool
    {
        return $this->ga->configured();
    }

    /** @return array{enabled: bool, recipients: string[], send_time: string, include_ai_summary: bool} */
    public function settings(): array
    {
        $raw = Setting::get(self::SETTINGS_KEY);
        $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $merged = [...self::DEFAULTS, ...$stored];

        return [
            'enabled' => (bool) $merged['enabled'],
            'recipients' => array_values(array_filter(array_map(
                fn ($e) => strtolower(trim((string) $e)),
                (array) $merged['recipients'],
            ), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)),
            'send_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $merged['send_time'])
                ? $merged['send_time']
                : self::DEFAULTS['send_time'],
            'include_ai_summary' => (bool) $merged['include_ai_summary'],
        ];
    }

    public function saveSettings(array $settings): array
    {
        Setting::set(self::SETTINGS_KEY, json_encode([
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'recipients' => array_values((array) ($settings['recipients'] ?? [])),
            'send_time' => (string) ($settings['send_time'] ?? self::DEFAULTS['send_time']),
            'include_ai_summary' => (bool) ($settings['include_ai_summary'] ?? true),
        ]));

        return $this->settings();
    }

    /**
     * Send time for the scheduler. Static + exception-safe on purpose:
     * routes/console.php evaluates this on EVERY artisan invocation, so a
     * missing DB (fresh clone, migrations mid-run) must fall back to the
     * default rather than break artisan entirely.
     */
    public static function sendTime(): string
    {
        try {
            $raw = Setting::get(self::SETTINGS_KEY);
            $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            $time = (string) ($stored['send_time'] ?? '');

            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::DEFAULTS['send_time'];
        } catch (Throwable) {
            return self::DEFAULTS['send_time'];
        }
    }

    /**
     * Build the email payload: yesterday + month-to-date GA reports and the
     * optional AI summary.
     */
    public function build(): array
    {
        $yesterday = Carbon::now('Asia/Manila')->subDay()->startOfDay();
        $mtdFrom = $yesterday->copy()->startOfMonth();

        $daily = $this->ga->websiteReport($yesterday->toDateString(), $yesterday->toDateString());
        $mtd = $this->ga->websiteReport($mtdFrom->toDateString(), $yesterday->toDateString());

        $payload = [
            'date_label' => $yesterday->format('M j, Y'),
            'mtd_label' => $mtdFrom->format('M j').' – '.$yesterday->format('M j, Y'),
            'daily' => $daily,
            'mtd' => $mtd,
            'ai_summary' => null,
        ];

        if ($this->settings()['include_ai_summary']) {
            $payload['ai_summary'] = $this->aiSummary($payload);
        }

        return $payload;
    }

    /** Three-sentence executive summary. Numbers come only from the payload. */
    private function aiSummary(array $payload): ?string
    {
        $key = config('services.openai.key');
        if (! $key) {
            return null;
        }

        try {
            $facts = json_encode([
                'date' => $payload['date_label'],
                'yesterday' => [
                    'totals' => $payload['daily']['totals'] ?? null,
                    'change_vs_previous_day' => $payload['daily']['change_vs_previous'] ?? null,
                    'top_sources' => array_slice($payload['daily']['sources'] ?? [], 0, 5),
                    'top_pages' => array_slice($payload['daily']['top_pages'] ?? [], 0, 5),
                    'lead_events' => $payload['daily']['lead_events'] ?? null,
                ],
                'month_to_date_totals' => $payload['mtd']['totals'] ?? null,
            ]);

            $response = OpenAI::client($key)->chat()->create([
                'model' => config('services.openai.chat_model', 'gpt-5.4-mini'),
                'temperature' => 0.2,
                'max_tokens' => 220,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You summarize website analytics for the executives of Filipino Homes, a Philippine real-estate portal. '
                            .'Write 2-3 plain-English sentences. Use ONLY the numbers provided — never invent, extrapolate, or estimate. '
                            .'Lead with the most notable change, mention the top traffic source, and keep a factual, benefit-framed tone. '
                            .'No markdown, no greetings, no sign-off.',
                    ],
                    ['role' => 'user', 'content' => "Yesterday's website data:\n{$facts}"],
                ],
            ]);

            $text = trim((string) ($response->choices[0]->message->content ?? ''));

            return $text !== '' ? $text : null;
        } catch (Throwable) {
            return null; // the email is complete without it
        }
    }
}
