<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The money behind a "For Assume" (pasalo) listing — one row per listing.
 *
 * `cash_out_price` mirrors listings.price (what the buyer hands the current
 * owner); everything else describes the balance they then take over. See the
 * create_assume_details_table migration for why each column exists.
 *
 * Raw storage only — every derived figure (cash needed now, all-in cost,
 * estimated monthly) comes from AssumeComputation so one formula serves the
 * listing page, the cards, the hub and the API alike.
 */
class AssumeDetail extends Model
{
    public const STAGE_EQUITY = 'equity';
    public const STAGE_LOAN = 'loan';

    public const STAGES = [self::STAGE_EQUITY, self::STAGE_LOAN];
    public const LENDER_TYPES = ['bank', 'pag_ibig', 'in_house', 'not_yet'];
    public const LOAN_STATUSES = ['not_applied', 'in_process', 'approved', 'released'];
    public const ARREARS_STATUSES = ['none', '1_2_months', '3_plus_months'];
    public const TRANSFER_CONSENT = ['yes_written', 'pending', 'no'];
    public const TURNOVER_STATUSES = ['rfo', 'under_construction'];

    protected $fillable = [
        'listing_id',
        'stage',
        'cash_out_price',
        'total_contract_price',
        'amount_paid_to_date',
        'remaining_equity_monthly',
        'remaining_equity_months',
        'loan_balance',
        'existing_monthly_amortization',
        'transfer_fee',
        'reservation_fee',
        'other_fees',
        'lender_type',
        'lender_name',
        'loan_status',
        'arrears_status',
        'arrears_amount',
        'developer_allows_transfer',
        'developer_name',
        'turnover_status',
        'turnover_date',
    ];

    protected $casts = [
        'cash_out_price' => 'decimal:2',
        'total_contract_price' => 'decimal:2',
        'amount_paid_to_date' => 'decimal:2',
        'remaining_equity_monthly' => 'decimal:2',
        'remaining_equity_months' => 'integer',
        'loan_balance' => 'decimal:2',
        'existing_monthly_amortization' => 'decimal:2',
        'transfer_fee' => 'decimal:2',
        'reservation_fee' => 'decimal:2',
        'other_fees' => 'decimal:2',
        'arrears_amount' => 'decimal:2',
        'turnover_date' => 'date',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * The "For Assume" category name. The id is pinned to 4 by
     * 2026_11_13_000001_add_for_assume_category, but resolve by NAME so a
     * differently-seeded environment still works; the id is only the fallback.
     */
    public const CATEGORY_NAME = 'For Assume';
    public const CATEGORY_ID_FALLBACK = 4;

    public static function categoryId(): int
    {
        return Cache::remember('for_assume_category_id', 3600, function () {
            $id = DB::table('categories')->where('name', self::CATEGORY_NAME)->value('id');

            return (int) ($id ?: self::CATEGORY_ID_FALLBACK);
        });
    }

    /** True when the given category id is the For Assume category. */
    public static function isAssumeCategory($categoryId): bool
    {
        return $categoryId !== null && (int) $categoryId === self::categoryId();
    }
}
