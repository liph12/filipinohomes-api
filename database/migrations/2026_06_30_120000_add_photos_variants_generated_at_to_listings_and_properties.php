<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when every photo on the record has its responsive WebP width
        // variants on S3 (written by GenerateImageVariantsJob / the upload
        // hooks). The API emits srcset ONLY when this is non-null, so the
        // backfill is partial-safe — un-flagged records render the original.
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('photos_variants_generated_at')->nullable()->index();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('photos_variants_generated_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['photos_variants_generated_at']);
            $table->dropColumn('photos_variants_generated_at');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['photos_variants_generated_at']);
            $table->dropColumn('photos_variants_generated_at');
        });
    }
};
