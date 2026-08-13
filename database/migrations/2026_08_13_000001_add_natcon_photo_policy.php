<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-awardee "you must send a new photo" override.
 *
 * The campaign lets anyone with a photo on file keep it. That is wrong when the
 * photo on file is unusable for print — the events team is then left retouching
 * a low-resolution snapshot, or quietly substituting something. This flag lets a
 * reviewer say "not that one" for one awardee without changing the policy for
 * everybody else.
 *
 * ⚠️ First NATCON migration since the initial set, so `php artisan migrate` is
 *    part of the api2 deploy for this change. Every earlier NATCON deploy was
 *    code-only, and the habit of skipping migrate is exactly how a deploy goes
 *    green with the columns missing.
 *
 * No column is added for the photo COUNT requirement — that lives in config
 * (natcon.photo.required_count) because it has to be adjustable mid-campaign
 * without a deploy, and it is a rule about the event rather than a fact about
 * any one awardee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->boolean('requires_new_photo')->default(false)->after('retained_photo_url');

            // Shown to the awardee, so it explains rather than just refuses.
            // Kept short on purpose: it goes into an email and onto a phone.
            $table->string('requires_new_photo_note', 255)->nullable()->after('requires_new_photo');
            $table->timestamp('requires_new_photo_at')->nullable()->after('requires_new_photo_note');

            // Who decided. This flag makes a real person re-shoot a photo, so it
            // is attributable rather than anonymous.
            $table->foreignId('requires_new_photo_by')
                ->nullable()
                ->after('requires_new_photo_at')
                ->constrained('users')
                ->nullOnDelete();

            // Serves the admin's "who still has to re-shoot?" filter. Composite
            // with the event because every admin query is already event-scoped.
            $table->index(['natcon_event_id', 'requires_new_photo'], 'natcon_recip_event_reqphoto_idx');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->dropIndex('natcon_recip_event_reqphoto_idx');
            // Before the column: the FK's own index is what the constraint hangs
            // off, and dropping the column first leaves MySQL with an orphan.
            $table->dropConstrainedForeignId('requires_new_photo_by');
            $table->dropColumn([
                'requires_new_photo',
                'requires_new_photo_note',
                'requires_new_photo_at',
            ]);
        });
    }
};
