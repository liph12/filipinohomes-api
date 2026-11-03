<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Public office page (/about/offices/{slug}) content an admin can write:
// a description (falls back to a generated paragraph when empty), a contact
// email, and free-text opening hours (display only — not schema-formatted).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->text('description')->nullable()->after('address');
            $table->string('email')->nullable()->after('phone');
            $table->string('opening_hours')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn(['description', 'email', 'opening_hours']);
        });
    }
};
