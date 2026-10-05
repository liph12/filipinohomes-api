<?php

namespace App\Services\Inquiry;

use App\Models\Conversation;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settings + eligibility for the automatic inquiry approver (admin-configured
 * JSON blob in the `settings` KV table, same shape as
 * App\Services\Reports\WebsiteAnalyticsReportService). Window math
 * (activeDays/isWindowOpen) is kept as pure functions of $settings + a Carbon
 * instant so it can be unit-tested without touching the database — see
 * tests/Unit/InquiryAutoApprovalWindowTest.php.
 *
 * All day/time math is Philippine-local (Asia/Manila) regardless of the
 * server's clock — config('app.timezone') is UTC (see routes/console.php's
 * own warning about this), so "weekends" computed in UTC would misclassify
 * several hours of Manila Saturday/Monday.
 */
class InquiryAutoApprovalService
{
    public const SETTINGS_KEY = 'inquiry_auto_approval';
    public const LAST_RUN_KEY = 'inquiry_auto_approval_last_run';

    public const DAYS_WEEKDAYS = 'weekdays';
    public const DAYS_WEEKENDS = 'weekends';
    public const DAYS_ALL = 'all';
    public const DAYS_CUSTOM = 'custom';

    public const TIMEZONE = 'Asia/Manila';

    public const DEFAULTS = [
        'enabled' => false,
        'days' => self::DAYS_WEEKENDS,
        'custom_days' => [0, 6],
        'restrict_hours' => false,
        'start_time' => '00:00',
        'end_time' => '23:59',
        // Grace period before a freshly-pending inquiry is touched — gives a
        // human moderator who's online a head start before the scheduler
        // would also act on it.
        'delay_minutes' => 0,
        // The backlog guard: switching this on must never reach back and
        // mass-approve (and email agents about) months-old pending rows.
        'max_age_hours' => 72,
        // Caps how many accepts one run performs — each one is a synchronous
        // 1–3s SMTP round trip (no queue worker in production).
        'max_per_run' => 25,
    ];

    /** @return array{enabled:bool,days:string,custom_days:int[],restrict_hours:bool,start_time:string,end_time:string,delay_minutes:int,max_age_hours:int,max_per_run:int} */
    public function settings(): array
    {
        $raw = Setting::get(self::SETTINGS_KEY);
        $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $merged = [...self::DEFAULTS, ...(is_array($stored) ? $stored : [])];

        return [
            'enabled' => (bool) $merged['enabled'],
            'days' => in_array($merged['days'] ?? null, [
                self::DAYS_WEEKDAYS, self::DAYS_WEEKENDS, self::DAYS_ALL, self::DAYS_CUSTOM,
            ], true) ? $merged['days'] : self::DEFAULTS['days'],
            'custom_days' => $this->sanitizeDays((array) ($merged['custom_days'] ?? [])),
            'restrict_hours' => (bool) $merged['restrict_hours'],
            'start_time' => $this->validTimeOrDefault($merged['start_time'] ?? null, self::DEFAULTS['start_time']),
            'end_time' => $this->validTimeOrDefault($merged['end_time'] ?? null, self::DEFAULTS['end_time']),
            'delay_minutes' => max(0, min(1440, (int) $merged['delay_minutes'])),
            'max_age_hours' => max(1, min(24 * 30, (int) $merged['max_age_hours'])),
            'max_per_run' => max(1, min(200, (int) $merged['max_per_run'])),
        ];
    }

    public function saveSettings(array $input): array
    {
        Setting::set(self::SETTINGS_KEY, json_encode([
            'enabled' => (bool) ($input['enabled'] ?? false),
            'days' => (string) ($input['days'] ?? self::DEFAULTS['days']),
            'custom_days' => array_values((array) ($input['custom_days'] ?? [])),
            'restrict_hours' => (bool) ($input['restrict_hours'] ?? false),
            'start_time' => (string) ($input['start_time'] ?? self::DEFAULTS['start_time']),
            'end_time' => (string) ($input['end_time'] ?? self::DEFAULTS['end_time']),
            'delay_minutes' => (int) ($input['delay_minutes'] ?? self::DEFAULTS['delay_minutes']),
            'max_age_hours' => (int) ($input['max_age_hours'] ?? self::DEFAULTS['max_age_hours']),
            'max_per_run' => (int) ($input['max_per_run'] ?? self::DEFAULTS['max_per_run']),
        ]));

        return $this->settings();
    }

    /**
     * Resolve the "days" preset (or the sanitized custom set) to PHP/Carbon
     * day-of-week integers, 0 = Sunday … 6 = Saturday.
     *
     * @return int[]
     */
    public function activeDays(array $settings): array
    {
        return match ($settings['days'] ?? self::DAYS_WEEKENDS) {
            self::DAYS_WEEKDAYS => [1, 2, 3, 4, 5],
            self::DAYS_ALL => [0, 1, 2, 3, 4, 5, 6],
            self::DAYS_CUSTOM => $this->sanitizeDays((array) ($settings['custom_days'] ?? [])),
            default => [0, 6], // weekends
        };
    }

    /**
     * Pure predicate: is $now (any timezone — converted here) inside the
     * configured day+hour window? Handles an hours window that wraps past
     * midnight (end < start), e.g. 22:00–06:00.
     */
    public function isWindowOpen(array $settings, CarbonInterface $now): bool
    {
        $local = $now->copy()->setTimezone(self::TIMEZONE);

        if (! in_array($local->dayOfWeek, $this->activeDays($settings), true)) {
            return false;
        }

        if (! ($settings['restrict_hours'] ?? false)) {
            return true;
        }

        $start = $this->minutesSinceMidnight($settings['start_time'] ?? self::DEFAULTS['start_time']);
        $end = $this->minutesSinceMidnight($settings['end_time'] ?? self::DEFAULTS['end_time']);
        $minutesNow = $local->hour * 60 + $local->minute;

        if ($start <= $end) {
            return $minutesNow >= $start && $minutesNow <= $end;
        }

        // Wraps past midnight: open when after start OR before end.
        return $minutesNow >= $start || $minutesNow <= $end;
    }

    /** Builds (does not execute) the eligible-conversation query, Manila-clocked. */
    public function eligibleQuery(array $settings): Builder
    {
        $now = Carbon::now(self::TIMEZONE);
        $oldestAllowed = $now->copy()->subHours((int) $settings['max_age_hours']);
        $newestAllowed = $now->copy()->subMinutes((int) $settings['delay_minutes']);

        return Conversation::query()
            ->where('status', 'pending')
            ->whereNotNull('agent_user_id')
            ->whereHas('chat', fn ($q) => $q->where('type', 'listing'))
            ->whereBetween('created_at', [$oldestAllowed, $newestAllowed])
            ->orderBy('id');
    }

    public function eligibleCount(array $settings): int
    {
        return (clone $this->eligibleQuery($settings))->count();
    }

    /** @return Collection<int, Conversation> */
    public function eligible(array $settings, ?int $limit = null): Collection
    {
        return $this->eligibleQuery($settings)
            ->limit($limit ?? (int) $settings['max_per_run'])
            ->get();
    }

    /** Raw pending total — independent of the age/delay window, for the "N pending" card stat. */
    public function pendingTotal(): int
    {
        return Conversation::where('status', 'pending')->count();
    }

    public function recordRun(int $approved, int $failed): void
    {
        Setting::set(self::LAST_RUN_KEY, json_encode([
            'ran_at' => Carbon::now(self::TIMEZONE)->toIso8601String(),
            'approved' => $approved,
            'failed' => $failed,
        ]));
    }

    public function lastRun(): ?array
    {
        $raw = Setting::get(self::LAST_RUN_KEY);
        $stored = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($stored) ? $stored : null;
    }

    /** Human one-liner for the System Settings card subtitle. */
    public function summary(array $settings): string
    {
        if (! $settings['enabled']) {
            return 'Off — pending inquiries wait for a moderator';
        }

        $daysLabel = match ($settings['days']) {
            self::DAYS_WEEKDAYS => 'Weekdays',
            self::DAYS_ALL => 'Every day',
            self::DAYS_CUSTOM => $this->customDaysLabel($settings['custom_days']),
            default => 'Weekends',
        };

        $hoursLabel = $settings['restrict_hours']
            ? "{$settings['start_time']}–{$settings['end_time']}"
            : 'all day';

        return "{$daysLabel} · {$hoursLabel}";
    }

    private function customDaysLabel(array $days): string
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $labels = array_map(fn ($d) => $names[$d] ?? '?', $days);

        return $labels ? implode('/', $labels) : 'Custom';
    }

    /** @return int[] */
    private function sanitizeDays(array $days): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $days),
            fn ($d) => $d >= 0 && $d <= 6,
        )));
        sort($clean);

        return $clean ?: [0, 6];
    }

    private function validTimeOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $default;
    }

    private function minutesSinceMidnight(string $hhmm): int
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
            return 0;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
