<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name suffix (Jr., Sr., II, III, IV…) set on the profile page.
 *
 * Kept out of the assembled full_name for now: agent page URLs are built from
 * the name (buildAgentSlug), so adding it there would change those URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('suffix', 20)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('suffix');
        });
    }
};
