<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live raffle's winners, per convention: who won what, and when.
 *
 * Until now the raffle on /natcon/admin kept its winners in the browser's
 * localStorage — fine for one evening on one laptop, gone the moment the
 * admin opened another machine, and no way to look back at last year's
 * winners from this year's page. One table, tied to natcon_events because
 * the convention runs every year and a year's raffle must be its own.
 * Deliberately just the raffle's title, name, contact, prize: wherever the
 * name came from (the roster, or the admin's own list) the record is the same. `client_key` is
 * the browser's own key for the draw, unique per event, so a retried POST
 * cannot crown the same draw twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_raffle_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            // The browser's own key for the draw, unique per event, so a
            // retried POST cannot crown the same draw twice.
            $table->string('client_key', 64);

            // Which raffle of the evening ("Sponsor Night Raffle") — required.
            // A person may win once PER RAFFLE: the unique index below is that
            // rule at the database, whatever the stage does.
            $table->string('raffle', 120);

            // The winner, as they were when drawn — wherever the name came
            // from (the roster, or the admin's own list): name, contact, prize.
            $table->string('name', 191);
            $table->string('contact', 191)->nullable();
            $table->string('prize', 191)->nullable();
            $table->timestamp('drawn_at');

            $table->timestamps();
            // Removing a winner (or clearing a raffle) hides the row; nothing
            // is thrown away. A re-draw of the same name in the same raffle
            // RESTORES the hidden row rather than tripping the unique index.
            $table->softDeletes();
            // Who removed it (or cleared the raffle) — cleared again on restore.
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['natcon_event_id', 'client_key'], 'natcon_raffle_winner_key_uq');
            $table->unique(['natcon_event_id', 'raffle', 'name'], 'natcon_raffle_once_per_raffle_uq');
            // The panel reads one event's draws newest first; the year list
            // counts per event. Also backs the natcon_event_id FK.
            $table->index(['natcon_event_id', 'drawn_at'], 'natcon_raffle_winner_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_raffle_winners');
    }
};
