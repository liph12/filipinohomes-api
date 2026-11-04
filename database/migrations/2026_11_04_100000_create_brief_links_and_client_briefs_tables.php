<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyers & Sellers Link.
 *
 * Every agent has ONE permanent link (brief_links: one row per agent) with
 * two public pages on the site, /buy-with/{slug} and /sell-with/{slug}. A
 * client who opens either answers a four-step brief that is stored in
 * client_briefs with `kind` saying which form sent it and the answers in
 * `profile` as option keys (config/briefs.php). The slug is minted once from
 * the agent's name and never changes, so a printed QR keeps working; `code`
 * is the link's internal id that the public form posts to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brief_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->unique()->constrained('agents')->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('code', 12)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('client_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_link_id')->constrained('brief_links')->cascadeOnDelete();
            // Copied from the link so the agent's list needs no join.
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('kind', 10); // buyer | seller
            $table->string('name', 200);
            $table->string('phone_code', 8)->default('+63');
            $table->string('phone', 32);
            $table->string('email', 320);
            $table->string('contact_time', 20)->nullable();
            // Buyers only; a seller's asking price is in the profile.
            $table->string('budget', 20)->nullable();
            $table->text('message')->nullable();
            $table->json('profile');
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_briefs');
        Schema::dropIfExists('brief_links');
    }
};
