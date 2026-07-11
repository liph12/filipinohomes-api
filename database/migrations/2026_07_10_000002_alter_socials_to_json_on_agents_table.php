<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change agents.socials from VARCHAR(255) to JSON. The Agent model already
 * casts `socials` to an array, so the column has always held JSON — but the
 * 255-char string type truncated payloads once a handful of social URLs were
 * saved. Existing rows are valid JSON or NULL (written via the array cast),
 * so MySQL accepts the VARCHAR→JSON conversion in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('socials')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('socials')->nullable()->change();
        });
    }
};
