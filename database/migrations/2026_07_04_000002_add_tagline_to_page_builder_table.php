<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            // Agent tagline — the short line under the agent's name in the
            // About card (falls back to a default when empty).
            $table->string('tagline')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->dropColumn('tagline');
        });
    }
};
