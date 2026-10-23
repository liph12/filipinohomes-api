<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI-assistant visibility log — the closest a site can get to "how often
     * do ChatGPT & co. show our listings, and how often do people click".
     * Three kinds of row (see App\Services\Audience\AiVisibilityService):
     *   - view  : an assistant fetched the page LIVE for a user's question
     *             (ChatGPT-User, Perplexity-User, Claude-User…) — logged by the
     *             web's proxy.ts, server-to-server. A proxy for "appeared in an
     *             answer"; no assistant reports real impressions to sites.
     *   - crawl : the assistant's search crawler indexed the page
     *             (OAI-SearchBot, PerplexityBot, Claude-SearchBot).
     *   - click : a person landed here from an assistant (utm_source /
     *             referrer), logged by the web's VisitTracker. Exact.
     * No IP or visitor id is stored — this is per-page counting only.
     */
    public function up(): void
    {
        Schema::create('ai_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);           // view | crawl | click
            $table->string('source', 32);        // chatgpt, perplexity, claude, gemini…
            $table->string('agent', 64)->nullable(); // UA token for view/crawl
            $table->string('path', 512);
            $table->timestamp('created_at')->nullable();

            $table->index(['kind', 'created_at']);
            $table->index(['path', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_events');
    }
};
