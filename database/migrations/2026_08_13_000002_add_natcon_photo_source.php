<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a kept photo and an uploaded photo be the same kind of thing.
 *
 * ─── Why ────────────────────────────────────────────────────────────────────
 * The page used to ask "keep ONE of these, or send THREE new ones", which is
 * incoherent: selecting a single photo makes no sense when the event wants three
 * to choose from. What the organizers actually want is a set of three, however
 * it is assembled — two kept from last year plus one new is a perfectly good
 * answer.
 *
 * That only works if both live in one place. `retained_photo_url` is a single
 * string and cannot express "kept two of them", so keeping a photo now writes a
 * natcon_photo_submissions row like any other, marked `source = lr_retained`.
 *
 * Everything downstream then works unchanged: activePhotos() counts the set,
 * syncResponseState() derives completion from it, the admin's "use this one"
 * can pick a kept photo as easily as an uploaded one, and the CSV export needs
 * no special case.
 *
 * `s3_key` becomes nullable because a retained photo is not ours — it stays
 * where Leuterio Realty serves it, and copying it into our bucket would be a
 * second source of truth for the same image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_photo_submissions', function (Blueprint $table) {
            // Explicit column rather than inferring from a null s3_key: an
            // implicit discriminator is the sort of thing that survives until
            // someone adds a third source and cannot express it.
            $table->string('source', 16)->default('uploaded')->after('natcon_event_id');
            $table->string('s3_key', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('natcon_photo_submissions', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        // s3_key is deliberately NOT returned to NOT NULL. Any retained row
        // written while this was live has a null there, so the constraint would
        // fail to apply and take the rollback down with it.
    }
};
