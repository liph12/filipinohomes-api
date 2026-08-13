<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Do-not-send list, checked before every NATCON send.
     *
     * Nothing in this codebase has ever needed one because all mail to date has been
     * transactional and one-recipient. A campaign is different: AWS SES puts an
     * account "under review" above a 5% bounce rate and PAUSES SENDING above 10%,
     * and a pause takes login OTPs (UserController) and inquiry notifications down
     * with it. This table is the cheapest insurance against that.
     *
     * Not scoped to an event — a hard bounce or a complaint is a fact about the
     * address, not about NATCON 2026.
     */
    public function up(): void
    {
        Schema::create('natcon_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();   // normalized lower+trim
            // bounce | complaint | unsubscribe | manual | invalid_domain
            $table->string('reason', 24);
            $table->string('detail', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_suppressions');
    }
};
