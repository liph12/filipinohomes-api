<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            // Semantic version string the mobile app compares against, e.g. "1.2.0".
            $table->string('version', 32);
            $table->string('platform', 16)->default('android');
            // External download link (APK URL or web page); not a stored file.
            $table->string('download_url');
            $table->text('notes')->nullable();
            // Exactly one row per platform should be the latest; enforced in the
            // controller (set-this clears the others).
            $table->boolean('is_latest')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'is_latest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
