<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            // Listing flyers generated from the flyer editor — kept separate
            // from the free-form photo gallery.
            $table->json('flyers')->nullable()->after('gallery');
        });
    }

    public function down(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->dropColumn('flyers');
        });
    }
};
