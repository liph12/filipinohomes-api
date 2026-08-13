<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One submission per recipient, upserted on re-submit.
     *
     * ─── Two JSON columns, because they answer different questions ──────────
     *
     *   answers           {"polo_shirt_size":"l","dietary_restrictions":"no pork"}
     *                     Keyed by field key. This is what you QUERY.
     *
     *   answers_snapshot  [{key,label,type,value,display_value}, …]
     *                     Labels frozen as the awardee saw them. This is what you
     *                     DISPLAY and export.
     *
     * The snapshot exists because renaming "Polo Shirt Size" to "Shirt Size
     * (Unisex)" in September must not rewrite what August's export said. Same
     * philosophy as LogsActivity snapshotting user_role/user_name.
     *
     * ─── There is deliberately no natcon_form_answers table ─────────────────
     * An earlier design normalized every answer into its own row so aggregation
     * could use an indexed FK. It was write-only: nothing ever read a value back
     * out, and its one read was a COUNT. At one row per recipient (~1,000), the
     * JSON aggregate is a ~2MB scan in single-digit milliseconds:
     *
     *   SELECT JSON_UNQUOTE(JSON_EXTRACT(answers,'$."polo_shirt_size"')) v,
     *          COUNT(*) n
     *   FROM natcon_form_submissions WHERE natcon_event_id = ? GROUP BY v;
     *
     * If a future event ever outgrows that, the escape hatch is one VIRTUAL
     * generated column + index — a sub-second ALTER at this size. Deliberately
     * NOT pre-built, because it would hardcode an admin-editable field key back
     * into the schema, which is exactly what the form builder exists to prevent.
     *
     * ⚠️ `answers` must never contain a key with a null/empty value. The
     *    "has this field been answered?" check is JSON_CONTAINS_PATH, and a
     *    stored null would count as answered — which would then refuse to delete
     *    an unused field. App\Natcon\Services\FormService omits empty keys entirely.
     */
    public function up(): void
    {
        Schema::create('natcon_form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();
            $table->foreignId('natcon_recipient_id')->constrained('natcon_recipients')->cascadeOnDelete();

            $table->json('answers')->nullable();
            $table->json('answers_snapshot')->nullable();

            $table->string('submitted_ip', 45)->nullable();
            $table->string('submitted_user_agent', 255)->nullable();

            $table->timestamps();

            // Explicit name: the auto-generated one overflows MySQL's 64-char limit.
            $table->unique(['natcon_event_id', 'natcon_recipient_id'], 'natcon_submission_event_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_form_submissions');
    }
};
