<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->date('status_change_date')->nullable()->after('status');
            $table->text('status_remark')->nullable()->after('status_change_date');
            $table->date('ats_expiration_date')->nullable()->after('status_remark');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'status_change_date',
                'status_remark',
                'ats_expiration_date',
            ]);
        });
    }
};