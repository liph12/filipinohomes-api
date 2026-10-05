<?php

use App\Services\Inquiry\InquiryAutoApprovalService;
use Illuminate\Support\Carbon;

/**
 * Pure-function coverage for the day/hour window math — no DB, no app
 * container (InquiryAutoApprovalService has no constructor dependencies for
 * activeDays()/isWindowOpen()). This is the only part of the feature that
 * can be safely unit-tested in this repo: there are no tests for
 * chats/conversations, and tests/TestCase's RefreshDatabase guard is
 * deliberately commented out (see its own doc comment re: the 2026-09-16
 * incident), so anything touching the database belongs in a manual/feature
 * verification pass instead.
 *
 * The case that matters most: a Carbon instant that is still Friday in UTC
 * but already Saturday in Asia/Manila (+8). config('app.timezone') is UTC
 * (see routes/console.php's own warning about this), so if isWindowOpen()
 * ever forgot to convert to Manila first, "weekends" would silently exclude
 * several hours of real Manila Saturday.
 */

function autoApproval(): InquiryAutoApprovalService
{
    return new InquiryAutoApprovalService();
}

test('activeDays resolves each preset to the right day-of-week set', function () {
    $svc = autoApproval();

    expect($svc->activeDays(['days' => 'weekdays']))->toBe([1, 2, 3, 4, 5]);
    expect($svc->activeDays(['days' => 'weekends']))->toBe([0, 6]);
    expect($svc->activeDays(['days' => 'all']))->toBe([0, 1, 2, 3, 4, 5, 6]);
    expect($svc->activeDays(['days' => 'custom', 'custom_days' => [1, 3, 5]]))->toBe([1, 3, 5]);
});

test('activeDays sanitizes a custom day set: dedupes, sorts, drops out-of-range, falls back to weekends when empty', function () {
    $svc = autoApproval();

    expect($svc->activeDays(['days' => 'custom', 'custom_days' => [5, 1, 1, 9, -1, 3]]))->toBe([1, 3, 5]);
    expect($svc->activeDays(['days' => 'custom', 'custom_days' => []]))->toBe([0, 6]);
});

test('activeDays falls back to weekends for an unrecognized preset', function () {
    expect(autoApproval()->activeDays(['days' => 'nonsense']))->toBe([0, 6]);
});

test('isWindowOpen gates purely on the day when hours are not restricted', function () {
    $svc = autoApproval();
    $settings = ['days' => 'weekends', 'restrict_hours' => false];

    // Saturday and Sunday (any hour) → open.
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-03 09:00:00', 'Asia/Manila')))->toBeTrue(); // Sat
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-04 23:59:00', 'Asia/Manila')))->toBeTrue(); // Sun
    // Monday → closed.
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 09:00:00', 'Asia/Manila')))->toBeFalse();
});

test('isWindowOpen converts a UTC instant to Manila before deciding the day', function () {
    $svc = autoApproval();
    $settings = ['days' => 'weekends', 'restrict_hours' => false];

    // 2026-10-02 is a Friday. 2026-10-02 18:00 UTC = 2026-10-03 02:00 Manila
    // (+8), i.e. already Saturday. A naive same-timezone check would say
    // Friday and wrongly close the window.
    $fridayEveningUtc = Carbon::parse('2026-10-02 18:00:00', 'UTC');
    expect($fridayEveningUtc->copy()->setTimezone('Asia/Manila')->dayOfWeek)->toBe(Carbon::SATURDAY);
    expect($svc->isWindowOpen($settings, $fridayEveningUtc))->toBeTrue();

    // The reverse edge: 2026-10-04 (Sun) 17:00 UTC = 2026-10-05 (Mon) 01:00
    // Manila — already past the weekend in Manila even though it's still
    // Sunday in UTC.
    $sundayEveningUtc = Carbon::parse('2026-10-04 17:00:00', 'UTC');
    expect($sundayEveningUtc->copy()->setTimezone('Asia/Manila')->dayOfWeek)->toBe(Carbon::MONDAY);
    expect($svc->isWindowOpen($settings, $sundayEveningUtc))->toBeFalse();
});

test('isWindowOpen applies the hour range on an eligible day', function () {
    $svc = autoApproval();
    $settings = [
        'days' => 'all',
        'restrict_hours' => true,
        'start_time' => '08:00',
        'end_time' => '18:00',
    ];

    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila')))->toBeTrue();
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 18:00:00', 'Asia/Manila')))->toBeTrue();
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 07:59:00', 'Asia/Manila')))->toBeFalse();
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 18:01:00', 'Asia/Manila')))->toBeFalse();
});

test('isWindowOpen handles an hour range that wraps past midnight', function () {
    $svc = autoApproval();
    $settings = [
        'days' => 'all',
        'restrict_hours' => true,
        'start_time' => '22:00',
        'end_time' => '06:00',
    ];

    // Inside the wrapped range (after start, before midnight).
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 23:30:00', 'Asia/Manila')))->toBeTrue();
    // Inside the wrapped range (after midnight, before end).
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 05:30:00', 'Asia/Manila')))->toBeTrue();
    // Outside the wrapped range, squarely in the daytime gap.
    expect($svc->isWindowOpen($settings, Carbon::parse('2026-10-05 12:00:00', 'Asia/Manila')))->toBeFalse();
});

test('summary reports the disabled state plainly and an enabled state with its day/hour shape', function () {
    $svc = autoApproval();

    expect($svc->summary(['enabled' => false]))->toContain('Off');

    expect($svc->summary([
        'enabled' => true,
        'days' => 'weekends',
        'restrict_hours' => false,
    ]))->toBe('Weekends · all day');

    expect($svc->summary([
        'enabled' => true,
        'days' => 'custom',
        'custom_days' => [1, 3, 5],
        'restrict_hours' => true,
        'start_time' => '08:00',
        'end_time' => '18:00',
    ]))->toBe('Mon/Wed/Fri · 08:00–18:00');
});
