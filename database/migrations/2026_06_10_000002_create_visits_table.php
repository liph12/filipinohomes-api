<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anonymous-visitor acquisition log. The public web pings POST /track/visit
     * once per session with the referrer + utm params; the API classifies a
     * `channel` (facebook, instagram, google, …) and stores one row. Powers the
     * "where visitors come from" tracking + the audience counts endpoint.
     */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            // Persistent client id from the web's localStorage (groups a
            // visitor's sessions). Not a user id.
            $table->string('visitor_id', 64)->nullable()->index();
            // Resolved acquisition channel (utm_source first, else referrer).
            $table->string('channel', 32)->default('direct');
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('landing_path', 512)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['channel', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
