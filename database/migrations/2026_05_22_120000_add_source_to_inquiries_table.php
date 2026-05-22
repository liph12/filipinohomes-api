<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the form-source label on contact inquiries.
 *
 * Three public-facing forms feed the same `inquiries` table:
 *   - home_get_in_touch  (Get In Touch section on the home page)
 *   - maintenance_page   (the Maintenance form)
 *   - contact_page       (the dedicated Contact Us page — richer payload)
 *
 * UserController::sendInquiry already validates a `source` request field
 * (max 64 chars) and threads it into the mail subject, but never wrote
 * it to the DB. This migration adds the column + backfills the obvious
 * subset of legacy rows.
 *
 * Backfill heuristic — contact-us submissions are persisted by
 * UserController::sendContactUs with a metadata header prefix
 * ("Inquiry Type: …", "Subject: …", or "Phone: …") prepended before two
 * newlines. Those rows are unambiguously source=contact_page. The
 * remaining null rows are legacy Get-In-Touch / Maintenance submissions
 * that we can't disambiguate from message text alone — they stay null and
 * surface under the "All" filter in the admin UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('source', 64)->nullable()->after('message');
            $table->index('source');
        });

        DB::table('inquiries')
            ->whereNull('source')
            ->where(function ($q) {
                $q->where('message', 'like', 'Inquiry Type:%')
                  ->orWhere('message', 'like', 'Subject:%')
                  ->orWhere('message', 'like', 'Phone:%');
            })
            ->update(['source' => 'contact_page']);
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
