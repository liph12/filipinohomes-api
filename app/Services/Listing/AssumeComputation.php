<?php

namespace App\Services\Listing;

use App\Models\AssumeDetail;
use App\Services\OpenAI\CostCommandService;

/**
 * The ONE place "For Assume" money is turned into the figures buyers see.
 *
 * Every surface — listing page, search card, the /pasalo hub, the API — reads
 * this, so the same listing can never quote two different monthlies. That was
 * the original failure: the listing page ran a mortgage on the cash-out alone
 * and advertised ₱3,958/month on a unit with ₱3.1M still owed.
 *
 * Raw inputs live on assume_details; rates come from AssumeFinanceSettings so
 * they can be corrected without a deploy.
 */
class AssumeComputation
{
    public function __construct(private AssumeFinanceSettings $settings)
    {
    }

    /**
     * @param  float|null  $floorArea  sqm, for the per-sqm comparison
     * @return array<string,mixed>
     */
    public function compute(AssumeDetail $d, ?float $floorArea = null): array
    {
        $rates = $this->settings->settings();

        $cashOut = (float) $d->cash_out_price;
        $transfer = (float) $d->transfer_fee;
        $reservation = (float) $d->reservation_fee;
        $other = (float) $d->other_fees;

        // Equity still to pay the developer, when the seller hasn't reached
        // loan stage yet.
        $remainingEquityTotal = (float) ($d->remaining_equity_monthly ?? 0) * (int) ($d->remaining_equity_months ?? 0);
        $loanBalance = (float) ($d->loan_balance ?? 0);

        // What the buyer hands over on day one…
        $cashNeededNow = $cashOut + $transfer + $reservation + $other;
        // …and what the unit ends up costing them in total. This is the number
        // the old page was missing entirely.
        $allInCost = $cashNeededNow + $remainingEquityTotal + $loanBalance;

        // Never divide by zero — a listing with no floor area simply has no
        // per-sqm figure rather than a nonsense one.
        $allInPerSqm = ($floorArea !== null && $floorArea >= 1) ? $allInCost / $floorArea : null;

        // Positive = the seller is asking more than they've paid in.
        $premiumOrDiscount = $d->amount_paid_to_date !== null
            ? $cashOut - (float) $d->amount_paid_to_date
            : null;

        $hasLoan = $loanBalance > 0;
        $inHouse = $d->lender_type === 'in_house';

        // Bank and Pag-IBIG estimates only mean something for a balance that a
        // bank or Pag-IBIG would actually carry. In-house financing is the
        // developer's own schedule, so the seller's current amortisation is
        // the honest primary figure there.
        $bankMonthly = $hasLoan
            ? CostCommandService::paymentFor($loanBalance, $rates['bank_rate_pct'], $rates['bank_term_years'])
            : null;

        $pagibigEligible = $hasLoan && $loanBalance <= $rates['pagibig_max_loan'];
        $pagibigMonthly = $pagibigEligible
            ? CostCommandService::paymentFor($loanBalance, $rates['pagibig_rate_pct'], $rates['pagibig_term_years'])
            : null;

        $dti = $rates['dti_ratio'];

        return [
            'stage' => $d->stage,
            'cash_out_price' => $this->money($cashOut),
            'fees_total' => $this->money($transfer + $reservation + $other),
            'cash_needed_now' => $this->money($cashNeededNow),
            'remaining_equity_total' => $this->money($remainingEquityTotal),
            'loan_balance' => $this->money($loanBalance),
            'all_in_cost' => $this->money($allInCost),
            'all_in_price_per_sqm' => $allInPerSqm !== null ? $this->money($allInPerSqm) : null,
            'premium_or_discount' => $premiumOrDiscount !== null ? $this->money($premiumOrDiscount) : null,

            // Null means "we are not quoting one", which the UI must render as
            // such rather than as zero.
            'bank_monthly' => $bankMonthly !== null ? $this->money($bankMonthly) : null,
            'pagibig_monthly' => $pagibigMonthly !== null ? $this->money($pagibigMonthly) : null,
            'pagibig_eligible' => $pagibigEligible,
            'existing_monthly_amortization' => $d->existing_monthly_amortization !== null
                ? $this->money((float) $d->existing_monthly_amortization)
                : null,
            // Which monthly the page should lead with.
            'primary_monthly_source' => $inHouse && $d->existing_monthly_amortization !== null
                ? 'existing'
                : ($hasLoan ? 'estimate' : 'none'),

            'min_income_bank' => $bankMonthly !== null ? $this->money($bankMonthly / $dti) : null,
            'min_income_pagibig' => $pagibigMonthly !== null ? $this->money($pagibigMonthly / $dti) : null,

            // Echoed so a page can state the assumptions it priced with, and
            // so a cached page is self-describing after a rate change.
            'rates' => $rates,
        ];
    }

    /** Display money: whole centavos, full precision kept until here. */
    private function money(float $v): float
    {
        return round($v, 2);
    }
}
