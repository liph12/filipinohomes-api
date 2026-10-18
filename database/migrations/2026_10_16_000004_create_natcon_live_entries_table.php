<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Join the raffle" entries from the live page — the ONLY source of a viewer's real name, email and
 * phone, since YouTube and Facebook expose none of those for commenters or viewers. One row per
 * entry; the email is unique per convention so a person can enter once (re-submitting updates their
 * row). `youtube_name` lets the admin cross-check an entry against the collected chat commenters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_live_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('email', 191);
            $table->string('phone', 32)->nullable();
            $table->string('youtube_name', 191)->nullable();
            $table->string('visitor_id', 64)->nullable();
            $table->timestamps();
            $table->unique(['natcon_event_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_live_entries');
    }
};
