<?php

use App\Models\CompanyEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every company event gets a PUBLIC PAGE (/events/{slug}) with a registration
 * form, so people can sign up for an open house or a caravan and the admin
 * reads the list — name, phone, address — from the dashboard.
 *
 *  - `slug` names the page. Generated once from the title and then STABLE:
 *    the link is what gets shared and printed, so a title fix must not break
 *    it (the Magazine model regenerates on rename — deliberately not copied).
 *  - `is_public` / `registration_open` are the two switches the editor shows.
 *  - `capacity` caps sign-ups; null = unlimited.
 *
 * Registrations are PII (a phone number and a home address), so they live in
 * their own table with a hard delete: nothing else points at them, and a
 * deleted row should be gone. One phone per event — the duplicate is a 422
 * with a friendly message, not a second row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_events', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->unique()->after('title');
            $table->boolean('is_public')->default(true)->after('status');
            $table->boolean('registration_open')->default(true)->after('is_public');
            $table->unsignedInteger('capacity')->nullable()->after('registration_open');
        });

        // Backfill: every existing row gets a slug the same way new ones will.
        CompanyEvent::query()->whereNull('slug')->orderBy('id')->each(function (CompanyEvent $e) {
            $base = Str::slug($e->title) ?: 'event';
            $slug = $base;
            $n = 1;
            while (CompanyEvent::where('slug', $slug)->where('id', '!=', $e->id)->exists()) {
                $slug = $base.'-'.$n++;
            }
            $e->slug = $slug;
            $e->saveQuietly();
        });

        Schema::create('company_event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_event_id')->constrained('company_events')->cascadeOnDelete();
            $table->string('name', 120);
            // Normalised PH mobile, 09XXXXXXXXX — see the controller.
            $table->string('phone', 32);
            $table->string('email', 191)->nullable();
            $table->string('address', 255);
            $table->string('notes', 500)->nullable();
            // Hashed, for abuse tracing only — never shown.
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_event_id', 'phone'], 'company_event_regs_event_phone_unique');
            $table->index(['company_event_id', 'created_at'], 'company_event_regs_event_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_event_registrations');
        Schema::table('company_events', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'is_public', 'registration_open', 'capacity']);
        });
    }
};
