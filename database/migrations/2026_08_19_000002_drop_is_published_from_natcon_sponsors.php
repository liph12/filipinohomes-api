<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sponsors have no draft state: assignment to a tier IS visibility (in a tier
 * = on the page; only in the library = not). The is_published flag duplicated
 * removal and only confused the admin UI, so it goes — column and its slot in
 * the page index.
 *
 * ⚠️ Order matters: the old composite index backs the natcon_event_id FK
 * (MySQL error 1553 on a direct drop), so the replacement index must exist
 * BEFORE the old one is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_sponsors', function (Blueprint $table) {
            $table->index(['natcon_event_id', 'tier', 'sort_order'], 'natcon_sponsor_order_idx');
        });

        Schema::table('natcon_sponsors', function (Blueprint $table) {
            $table->dropIndex('natcon_sponsor_page_idx');
            $table->dropColumn('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_sponsors', function (Blueprint $table) {
            $table->boolean('is_published')->default(true);
            $table->index(['natcon_event_id', 'tier', 'is_published', 'sort_order'], 'natcon_sponsor_page_idx');
        });

        Schema::table('natcon_sponsors', function (Blueprint $table) {
            $table->dropIndex('natcon_sponsor_order_idx');
        });
    }
};
