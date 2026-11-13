<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money behind a "For Assume" (pasalo) listing.
 *
 * On these listings `listings.price` is the CASH-OUT paid to the current
 * owner, not the property's price — the buyer also takes over whatever is
 * still owed to the developer, bank or Pag-IBIG. Without these columns the
 * site could only show the cash-out, which is how a Mandaue studio advertised
 * ₱3,958/month against a real commitment near ₱25,700 and was labelled "77%
 * below the average per sqm" when its all-in cost was above it.
 *
 * One row per listing (not per property): the assume terms belong to the
 * OFFER, and `price` + `category_id` both live on `listings`.
 *
 * Deal-shape columns are short strings with their allowed values in a comment
 * rather than enums — widening a MySQL enum needs raw DDL (see
 * 2026_10_01_000001_add_province_level_to_boundaries), and these lists will
 * grow. Money is decimal(20,2) to match listings.price.
 *
 * Deliberately NOT stored in v1: the tower/unit number (private, and there is
 * no verifier UI that would justify holding it) and the developer's
 * cancellation notice (a verifier-only signal — publishing an unreviewed claim
 * that someone's account is being cancelled is not a risk worth taking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assume_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();

            // Which half of the purchase the seller is still in. Decides which
            // balance the buyer assumes: the remaining equity, or the loan.
            $table->string('stage', 10); // equity | loan

            // ── Money ────────────────────────────────────────────────────
            // Mirrors listings.price so the computation never depends on the
            // listing row being loaded, and so an agent editing one can be
            // reconciled against the other.
            $table->decimal('cash_out_price', 20, 2);
            $table->decimal('total_contract_price', 20, 2)->nullable();
            $table->decimal('amount_paid_to_date', 20, 2)->nullable();
            $table->decimal('remaining_equity_monthly', 20, 2)->nullable();
            $table->unsignedSmallInteger('remaining_equity_months')->nullable(); // 0..120
            $table->decimal('loan_balance', 20, 2)->nullable();
            // What the seller pays the lender today — shown as the primary
            // monthly for in-house financing, where our bank/Pag-IBIG
            // estimates don't apply.
            $table->decimal('existing_monthly_amortization', 20, 2)->nullable();
            $table->decimal('transfer_fee', 20, 2)->default(0);
            $table->decimal('reservation_fee', 20, 2)->default(0);
            $table->decimal('other_fees', 20, 2)->default(0);

            // ── Deal shape (all optional; context for the buyer) ─────────
            $table->string('lender_type', 12)->nullable();   // bank | pag_ibig | in_house | not_yet
            $table->string('lender_name')->nullable();
            $table->string('loan_status', 12)->nullable();   // not_applied | in_process | approved | released
            $table->string('arrears_status', 12)->nullable(); // none | 1_2_months | 3_plus_months
            $table->decimal('arrears_amount', 20, 2)->nullable();
            $table->string('developer_allows_transfer', 12)->nullable(); // yes_written | pending | no
            $table->string('developer_name')->nullable();
            $table->string('turnover_status', 20)->nullable(); // rfo | under_construction
            $table->date('turnover_date')->nullable();

            $table->timestamps();

            // The hub lists by stage and sorts by cash-out.
            $table->index(['stage', 'cash_out_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assume_details');
    }
};
