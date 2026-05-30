<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('photos_migrated_at')->nullable()->index();
            // Free-form note left by the migration job — e.g.
            // "all_empty_soft_deleted", "partial_failure", or a short
            // human-readable summary of what was done.
            $table->string('photos_migration_note')->nullable();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('photos_migrated_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['photos_migrated_at']);
            $table->dropColumn(['photos_migrated_at', 'photos_migration_note']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['photos_migrated_at']);
            $table->dropColumn('photos_migrated_at');
        });
    }
};
