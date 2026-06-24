<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the public browse / location pages.
 *
 * `Listing::publiclyListed()->filter()->orderByDesc('updated_at')->paginate()`
 * filters on visibility (+ the SoftDeletes deleted_at IS NULL scope) and sorts
 * by updated_at. Neither visibility nor verification_status was indexed, so the
 * filter scanned and the sort filesorted. The composite covers filter+sort in
 * index order; verification_status gets its own index for the publiclyListed
 * `!= 'flagged'` predicate and the dashboard verification-count queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->index(['visibility', 'deleted_at', 'updated_at'], 'listings_public_browse_idx');
            $table->index('verification_status', 'listings_verification_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_public_browse_idx');
            $table->dropIndex('listings_verification_status_idx');
        });
    }
};
