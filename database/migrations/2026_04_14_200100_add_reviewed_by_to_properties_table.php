<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('ats_status');
                // Optional FK; keep nullable to avoid constraint issues during deploys
                // $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'reviewed_by')) {
                // $table->dropForeign(['reviewed_by']);
                $table->dropColumn('reviewed_by');
            }
        });
    }
};
