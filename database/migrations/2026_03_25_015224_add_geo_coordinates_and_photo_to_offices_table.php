<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->string('geo_coordinates')->nullable()->after('address');
            $table->string('photo')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('slug');
            $table->dropColumn('geo_coordinates');
            $table->dropColumn('photo');
        });
    }
};
