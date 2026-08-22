<?php

namespace App\Natcon\Services;

use App\Natcon\Models\NatconEvent;
use Illuminate\Support\Facades\Log;
use OpenAI;

/**
 * AI thank-you captions for the sponsor poster tool.
 *
 * Given a sponsor name + tier, produces 3 ready-to-post Facebook captions in
 * the house voice, grounded ONLY in the event row (name, dates, venue, year)
 * and an optional admin-supplied "about" line. Nothing per-year is hardcoded:
 * dates come from dateLabel(), hashtags from $event->year — rolling over to a
 * new convention is data entry, per the module rule.
 *
 * Hashtags are appended deterministically in PHP, never written by the model,
 * so the tag block is always present, always exact, and never eats into the
 * word budget.
 */
class SponsorCaptionService
{
    protected $client;

    /**
     * Tier → the exact phrase the caption must use. Singular/plural is
     * load-bearing ("our Star Benefactor" vs "one of our Major Sponsors"),
     * which is why the prompt forbids the model from restyling it.
     * `library` is the admin upload pool, not a sponsorship level — it has
     * no phrase here on purpose.
     */
    public const TIER_PHRASES = [
        'star'        => 'our Star Benefactor',
        'copresentor' => 'one of our Co-Presentors',
        'major'       => 'one of our Major Sponsors',
        'minor'       => 'one of our Minor Sponsors',
    ];

    /**
     * One hint is picked at random per call and steers the openings. This is
     * the cross-call variety mechanism: like GenerateDescriptionController's
     * decision not to whitelist `description`, we deliberately NEVER feed a
     * previous caption back as context — the model tends to echo / lightly
     * paraphrase its own prior output, so "Regenerate" would converge instead
     * of refresh. Rotating the angle of attack keeps repeated clicks for the
     * same sponsor from sounding alike without ever showing the model what it
     * said last time.
     */
    private const OPENING_HINTS = [
        'For this batch, lean gratitude-first: favor openings in the spirit of '
            . '"A huge thank you to…" or "Our heartfelt thanks to…" — still keeping '
            . 'all three openings distinct from each other.',
        'For this batch, lean honor-first: favor openings in the spirit of '
            . '"We are proud to have…" or "We are honored to welcome…" — still keeping '
            . 'all three openings distinct from each other.',
        'For this batch, lean recognition-first: favor openings in the spirit of '
            . '"Shining a spotlight on…" or "Join us in thanking…" — still keeping '
            . 'all three openings distinct from each other.',
    ];

    public function __construct()
    {
        // Bounded HTTP client — deliberate deviation from the siblings'
        // OpenAI::client(): Guzzle's default timeout is 0 (wait forever), so
        // a hung upstream would pin an api2 php-fpm worker until Cloudflare's
        // 524, and a few admins mashing Regenerate during an OpenAI incident
        // could exhaust the pool.
        $this->client = OpenAI::factory()
            ->withApiKey(config('services.openai.key'))
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 5]))
            ->make();
    }

    /**
     * Generate up to 3 thank-you captions for one sponsor.
     *
     * Returns ['captions' => string[]] with the hashtag block already appended
     * to each caption, or null when the model produced nothing usable.
     */
    public function generate(NatconEvent $event, string $sponsorName, string $tier, ?string $about = null): ?array
    {
        $levelPhrase = self::TIER_PHRASES[$tier] ?? null;
        if (! $levelPhrase) {
            return null;
        }

        $openingHint = self::OPENING_HINTS[array_rand(self::OPENING_HINTS)];
        $factsBlock  = $this->buildFactsBlock($event, $sponsorName, $levelPhrase, $about);

        $prompt = <<<PROMPT
        You write Facebook posts for Filipino Homes, thanking sponsors of its annual
        National Real Estate Convention (NATCON). Model the voice on this real post:

        "A huge thank you to EON Realty and Development Corp. for being one of our
        Minor Sponsors at the Filipino Homes National Real Estate Convention on
        October 19–20, 2025. Your partnership makes a big difference as we continue
        to inspire and empower real estate professionals nationwide."

        STRUCTURE — every caption:
        - Two short paragraphs of flowing prose, 40–75 words total.
        - Paragraph 1: thank the sponsor BY NAME (verbatim) for being {$levelPhrase}
          at the event, naming the event and its dates. Mention the venue in at most
          one of the three captions, and only where it reads naturally.
        - Paragraph 2: one or two sentences on what the partnership means — inspiring
          and empowering real estate professionals nationwide, elevating the industry,
          making the convention possible. Vary this sentiment across captions.

        VOICE: warm, professional, celebratory — a brand page, not a press release.
        No emojis. No exclamation-mark pileups (one "!" total is fine). No ALL CAPS.
        No hype filler ("amazing", "incredible journey", "beyond grateful").

        HARD RULES:
        - Use the sponsor name EXACTLY as given — never abbreviate, expand, or
          restyle it, never add or remove "Inc."/"Corp.".
        - Use the level phrase EXACTLY as given ("{$levelPhrase}") — never invert
          singular/plural.
        - NEVER invent facts about the sponsor — no industry, history, products,
          people, or superlatives that are not in the facts block. If "About this
          sponsor" is provided, you may weave in ONE detail from it; otherwise say
          nothing specific about them.
        - Dates, event name, and venue come ONLY from the facts block.
        - Do NOT write any hashtags — they are appended by the system afterward.

        VARIETY: return exactly 3 captions. Each must open differently and structure
        its gratitude differently — vary sentence order, the thanking verb, and the
        paragraph-2 sentiment, so no two captions share an opening clause.
        {$openingHint}

        FACTS (the complete universe of allowed facts):
        {$factsBlock}

        Return ONLY the function call.
        PROMPT;

        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "Sponsor: \"{$sponsorName}\" — {$levelPhrase}."],
            ],
            'functions' => [$this->toolDefinition()],
            'function_call' => ['name' => 'generate_sponsor_captions'],
        ]);

        $this->logUsage('natcon_sponsor_captions', $response);

        $result = $this->extractToolResponse($response);
        if (! is_array($result)) {
            return null;
        }

        $captions = array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? trim($c) : '',
            is_array($result['captions'] ?? null) ? $result['captions'] : [],
        )));

        if (empty($captions)) {
            return null;
        }

        // Hashtags are appended here, not by the model: deterministic, always
        // exact, and the year comes from the event row — never a literal.
        $hashtags = "#FilipinoHomes #RentPh #LRNatCon{$event->year} #NatCon{$event->year}";

        return [
            'captions' => array_map(
                fn ($c) => rtrim($c) . "\n\n" . $hashtags,
                array_slice($captions, 0, 3),
            ),
        ];
    }

    /**
     * The complete universe of facts the model may use. Everything per-year
     * is read off the event row; the optional "about" line is the ONLY place
     * sponsor-specific detail can enter.
     */
    protected function buildFactsBlock(NatconEvent $event, string $sponsorName, string $levelPhrase, ?string $about): string
    {
        $lines = [
            "Sponsor name (use verbatim): {$sponsorName}",
            "Sponsorship level phrase (use verbatim): {$levelPhrase}",
            "Event name: {$event->name}",
        ];

        if (($dates = $event->dateLabel()) !== '') {
            $lines[] = "Event dates: {$dates}";
        }

        if (! empty($event->venue)) {
            $lines[] = "Venue: {$event->venue}";
        }

        if ($about !== null && trim($about) !== '') {
            $lines[] = 'About this sponsor (the ONLY sponsor facts you may use): ' . trim($about);
        }

        return implode("\n", $lines);
    }

    protected function toolDefinition(): array
    {
        return [
            'name' => 'generate_sponsor_captions',
            'description' => 'Generate 3 distinct Facebook thank-you captions for one convention sponsor.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'captions' => [
                        'type' => 'array',
                        'minItems' => 3,
                        'maxItems' => 3,
                        'items' => [
                            'type' => 'string',
                            'description' => 'One complete caption: two short paragraphs separated by a blank line, 40–75 words, no hashtags.',
                        ],
                    ],
                ],
                'required' => ['captions'],
            ],
        ];
    }

    // Copied VERBATIM from App\Services\OpenAI\ListingCommandService so both
    // AI flows survive the same model quirks (plain-text fallback, ```json
    // fences). If a bug is fixed there, fix it here too.
    protected function extractToolResponse($response): ?array
    {
        $message = $response->choices[0]->message ?? null;

        // Prefer the function-call arguments. But the model sometimes ignores
        // the function call and returns the same JSON as plain text content
        // instead (the prompt explicitly allows this as a fallback). Reading
        // only functionCall used to drop that valid answer → silent null →
        // "Failed to generate title suggestions." So fall back to content.
        $raw = $message->functionCall->arguments ?? $message->content ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Strip ```json … ``` fences the model wraps bare-JSON fallbacks in.
        $raw = trim(preg_replace('/^\s*```(?:json)?|```\s*$/m', '', trim($raw)));

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Was invisible before — log so truncated/malformed output is
            // diagnosable instead of just surfacing as a generic failure.
            Log::warning('openai.extract_failed', ['raw' => mb_substr($raw, 0, 500)]);
            return null;
        }

        return $decoded;
    }

    /**
     * Log token usage from an OpenAI chat completion. Captures the model,
     * the called function, and prompt/completion/total tokens so we can
     * track cost per AI feature over time and catch sudden token-burn
     * regressions in a prompt change. Never throws — wrapped in try/catch
     * so a missing field never breaks the AI flow itself.
     *
     * (Copied VERBATIM from App\Services\OpenAI\ListingCommandService.)
     */
    protected function logUsage(string $flow, $response): void
    {
        try {
            $usage = $response->usage ?? null;
            Log::info("openai.{$flow}", [
                'model'             => $response->model ?? null,
                'prompt_tokens'     => $usage?->promptTokens,
                'completion_tokens' => $usage?->completionTokens,
                'total_tokens'      => $usage?->totalTokens,
            ]);
        } catch (\Throwable $e) {
            // never let logging break the request
        }
    }
}
