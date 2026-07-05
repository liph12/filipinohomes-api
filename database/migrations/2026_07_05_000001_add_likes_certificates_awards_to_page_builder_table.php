<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            // Portfolio media, separate from flyers/gallery.
            $table->json('certificates')->nullable()->after('flyers');
            $table->json('awards')->nullable()->after('certificates');
        });
    }

    public function down(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->dropColumn(['certificates', 'awards']);
        });
    }
};
