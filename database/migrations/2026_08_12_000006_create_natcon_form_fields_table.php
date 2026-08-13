<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customizable NATCON form — one row per question.
     *
     * ─── Why choices are JSON on this row, not their own table ──────────────
     * An earlier design gave choices a table so "how many XL polos do we order?"
     * could be a GROUP BY on an indexed FK. In practice nothing ever read a
     * choice id back out: answers are read from the submission's JSON, and the
     * only query against the choice table was a COUNT.
     *
     * The concurrency argument against JSON doesn't apply at this grain either.
     * It's an argument against putting the WHOLE form in one blob — with one row
     * per question, two admins editing different questions stay isolated, and
     * two admins editing the same question's choices already clobbered each
     * other (the old sync replaced the list wholesale). This merge does not make
     * concurrency worse. Please don't re-litigate it into a fourth table.
     *
     * What the merge costs, and how it's covered in code:
     *   - No UNIQUE(field, value) → NatconFormFieldController rejects duplicate
     *     choice values explicitly, because the reader keeps the LAST duplicate.
     *   - No choice ids → the API emits `value` as the id (unique per field and
     *     regex-constrained), which is what the admin UI uses for React keys.
     */
    public function up(): void
    {
        Schema::create('natcon_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            // Stable machine key, e.g. 'polo_shirt_size'. THE key in the answers
            // map, so it is frozen once anyone has answered.
            $table->string('key', 64);
            $table->string('label');
            $table->string('help_text', 512)->nullable();

            // section | short_text | long_text | email | phone | number | date
            // | select | radio | checkbox | image_upload
            $table->string('type', 24);

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Illustration on the question itself (e.g. the full polo artwork).
            $table->string('illustration_url', 2048)->nullable();

            // Options for select/radio/checkbox:
            //   [{value, label, help_text, image_url, is_active}]
            // `image_url` per choice is what makes the polo-size grid work — the
            // design shown beside each size rather than a bare dropdown.
            $table->json('choices')->nullable();

            // Type-specific extras: {min,max,max_length,placeholder,accept,
            // max_files,rows,layout,min_select,max_select,step,unit}. Varies per
            // type, never queried.
            $table->json('config')->nullable();

            // Page grouping, so "photo section first, form below" is data.
            $table->string('section', 64)->default('general');   // photo | general | merch

            $table->timestamps();

            $table->unique(['natcon_event_id', 'key']);
            $table->index(['natcon_event_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_form_fields');
    }
};
