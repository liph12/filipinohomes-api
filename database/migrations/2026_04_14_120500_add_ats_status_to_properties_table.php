<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->enum('ats_status', ['approve', 'pending', 'expired'])
                ->default('approve')
                ->after('ats_attachments');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'ats_status')) {
                $table->dropColumn('ats_status');
            }
        });
    }
};
