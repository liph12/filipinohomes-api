<?php

use App\Support\ChannelMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Relabels visits the old substring channel match got wrong, recomputed from each row's stored
     * utm_source + referrer with ChannelMatcher:
     *   - "twitter" rows: almost all were chatgpT.COm / copiloT.COm / qwanT.COm / reddiT.COm / yandeX.COM…
     *     (prod, 2026-10-30: ~5 of 7,553 were really Twitter);
     *   - "instagram" rows from hosts that merely contain "ig";
     *   - AI-assistant visits stored under another channel before assistants were checked first
     *     (gemini.google.com as google, utm_source=perplexity as referral…).
     * The click id isn't stored, so a row with neither a tag nor a referrer keeps its label (it may have
     * come from a twclid link). Yahoo Search still counts as email — unchanged on purpose.
     *
     * One-way: the old labels were wrong, so down() doesn't restore them.
     */
    private const AI = ['chatgpt', 'perplexity', 'gemini', 'copilot', 'claude', 'meta-ai', 'deepseek', 'grok', 'mistral'];

    private const AI_NEEDLES = ['chatgpt', 'openai', 'perplexity', 'gemini', 'bard.google', 'copilot', 'claude', 'meta.ai', 'deepseek', 'grok', 'mistral'];

    public function up(): void
    {
        DB::table('visits')
            ->where(function ($q) {
                $q->whereIn('channel', ['twitter', 'instagram']);
                foreach (self::AI_NEEDLES as $n) {
                    $q->orWhere('utm_source', 'like', "%{$n}%")->orWhere('referrer', 'like', "%{$n}%");
                }
            })
            ->select(['id', 'channel', 'utm_source', 'referrer'])
            ->chunkById(2000, function ($rows) {
                $moves = [];
                foreach ($rows as $r) {
                    $new = ChannelMatcher::resolve($r->utm_source, null, $r->referrer);
                    $fix = in_array($r->channel, ['twitter', 'instagram'], true)
                        ? $new !== 'direct'
                        : in_array($new, self::AI, true);
                    if ($fix && $new !== $r->channel) {
                        $moves[$new][] = $r->id;
                    }
                }
                foreach ($moves as $channel => $ids) {
                    DB::table('visits')->whereIn('id', $ids)->update(['channel' => $channel]);
                }
            });
    }

    public function down(): void {}
};
