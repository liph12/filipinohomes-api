<?php

namespace App\Services\Listing;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * The financing assumptions behind every "For Assume" estimate.
 *
 * These MUST be editable without a deploy. The Pag-IBIG promotional rate the
 * defaults carry expires 31 December 2026 — on 1 January someone in the office
 * has to be able to correct it, or every pasalo listing on the site quietly
 * quotes a monthly payment that no longer exists.
 *
 * Stored as one JSON blob under a single `settings` key, the same shape as
 * InquiryAutoApprovalService. Every value is CLAMPED on read, so a typo in the
 * admin form (700% instead of 7.00%) can't misprice the catalogue.
 *
 * ⚠️ Setting::get() is an uncached SELECT and these are read on public listing
 * pages, so reads go through a short cache; writes clear it.
 */
class AssumeFinanceSettings
{
    public const SETTINGS_KEY = 'assume_finance';
    private const CACHE_KEY = 'assume_finance_settings';
    private const CACHE_TTL = 300;

    public const DEFAULTS = [
        // Market bank rates sat around 7.7–7.8% when this was specified.
        'bank_rate_pct' => 7.75,
        'bank_term_years' => 20,
        // Pag-IBIG promo — see the expiry warning above.
        'pagibig_rate_pct' => 5.75,
        'pagibig_term_years' => 30,
        // Pag-IBIG will not lend beyond this, so above it we show no estimate
        // rather than an impossible one.
        'pagibig_max_loan' => 10000000,
        // Lenders cap the monthly payment at roughly a third of gross income;
        // this drives the "income needed" line.
        'dti_ratio' => 0.35,
    ];

    /**
     * @return array{bank_rate_pct:float,bank_term_years:int,pagibig_rate_pct:float,pagibig_term_years:int,pagibig_max_loan:float,dti_ratio:float}
     */
    public function settings(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $raw = Setting::get(self::SETTINGS_KEY);
            $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            $merged = [...self::DEFAULTS, ...(is_array($stored) ? $stored : [])];

            return $this->clamp($merged);
        });
    }

    public function saveSettings(array $input): array
    {
        // Clamp before storing as well as on read: the stored blob is what an
        // admin sees next time they open the form, and it should never show
        // them a value the site would refuse to use.
        Setting::set(self::SETTINGS_KEY, json_encode($this->clamp([...self::DEFAULTS, ...$input])));
        Cache::forget(self::CACHE_KEY);

        return $this->settings();
    }

    /** Bounds chosen to allow any believable Philippine rate and nothing absurd. */
    private function clamp(array $v): array
    {
        return [
            'bank_rate_pct' => max(0.0, min(30.0, (float) $v['bank_rate_pct'])),
            'bank_term_years' => max(1, min(40, (int) $v['bank_term_years'])),
            'pagibig_rate_pct' => max(0.0, min(30.0, (float) $v['pagibig_rate_pct'])),
            'pagibig_term_years' => max(1, min(40, (int) $v['pagibig_term_years'])),
            'pagibig_max_loan' => max(0.0, (float) $v['pagibig_max_loan']),
            // A DTI outside 10–60% would make the "income needed" line nonsense.
            'dti_ratio' => max(0.10, min(0.60, (float) $v['dti_ratio'])),
        ];
    }
}
