<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nearby_facilities', function (Blueprint $table) {
            // "Popular places" — malls, parks, and tourist attractions. Fed
            // into the listing-AI prompts so the model can mention nearby
            // landmarks like Ayala Center, Ayala Triangle, Magellan's Cross.
            $table->json('mall')->nullable()->after('police_station');
            $table->json('park')->nullable()->after('mall');
            $table->json('attraction')->nullable()->after('park');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nearby_facilities', function (Blueprint $table) {
            $table->dropColumn(['mall', 'park', 'attraction']);
        });
    }
};
