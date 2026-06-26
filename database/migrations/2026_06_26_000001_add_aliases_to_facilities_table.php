<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds rebrand-handling columns to the curated facility registry:
 *   - aliases:      former/alternate NAMES (e.g. "J Centre Mall") so the search
 *                   index still matches the old name and the page can note it.
 *   - former_slugs: former URL SLUGS (e.g. "j-centre-mall") so the frontend can
 *                   301 an old "near-{slug}" URL to the renamed facility.
 * Both nullable JSON; the registry is ~24 rows so no index is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            if (! Schema::hasColumn('facilities', 'aliases')) {
                $table->json('aliases')->nullable()->after('name');
            }
            if (! Schema::hasColumn('facilities', 'former_slugs')) {
                $table->json('former_slugs')->nullable()->after('slug');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            if (Schema::hasColumn('facilities', 'aliases')) {
                $table->dropColumn('aliases');
            }
            if (Schema::hasColumn('facilities', 'former_slugs')) {
                $table->dropColumn('former_slugs');
            }
        });
    }
};
