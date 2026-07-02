<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-inquiry origin stamp. The Inquiry Analytics "origin" views previously
 * attributed every inquiry to the client's user_info geo — a snapshot from
 * their last login, retroactively re-attributed when it changes. These columns
 * freeze the inquirer's geo AT SEND TIME (browser ipinfo payload, falling back
 * to user_info server-side), so each chats row carries where it actually came
 * from. Analytics read COALESCE(chats.origin_*, user_info.*) — historical rows
 * (null stamp) keep resolving via the login snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('origin_country', 8)->nullable()->after('type_id');   // ISO2 from ipinfo
            $table->string('origin_region', 96)->nullable()->after('origin_country');
            $table->string('origin_city', 96)->nullable()->after('origin_region');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['origin_country', 'origin_region', 'origin_city']);
        });
    }
};
