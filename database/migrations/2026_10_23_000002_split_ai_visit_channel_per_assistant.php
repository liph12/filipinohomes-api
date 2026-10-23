<?php

use App\Services\Audience\AiVisibilityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * visits.channel briefly stored every AI assistant as one "ai" channel.
     * It is now one lowercase channel per assistant (chatgpt, perplexity,
     * gemini…), so re-label those rows from their own utm_source/referrer.
     * Only rows this feature wrote as "ai" are touched. ai_events paths are
     * lowercased too, matching AiVisibilityService::normalizePath().
     */
    public function up(): void
    {
        $ai = app(AiVisibilityService::class);

        DB::table('visits')->where('channel', 'ai')->orderBy('id')
            ->each(function ($row) use ($ai) {
                $assistant = $ai->classifyClick($row->utm_source, null)
                    ?? $ai->classifyClick(null, $row->referrer);
                if ($assistant) {
                    DB::table('visits')->where('id', $row->id)->update(['channel' => $assistant]);
                }
            });

        DB::table('ai_events')->update(['path' => DB::raw('LOWER(path)')]);
        DB::table('ai_events')->where('source', 'meta')->update(['source' => 'meta-ai']);
    }

    public function down(): void
    {
        DB::table('visits')
            ->whereIn('channel', ['chatgpt', 'perplexity', 'gemini', 'copilot', 'claude', 'meta-ai', 'deepseek', 'grok', 'mistral'])
            ->update(['channel' => 'ai']);
    }
};
