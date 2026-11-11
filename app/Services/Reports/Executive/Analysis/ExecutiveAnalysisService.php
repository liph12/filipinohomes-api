<?php

namespace App\Services\Reports\Executive\Analysis;

use App\Services\Reports\Executive\ExecutiveReportService;
use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportRange;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenAI;
use OpenAI\Contracts\ClientContract;

/**
 * The Executive Report's AI Analysis — a headline and a few bullets on what
 * the period's numbers say, written by the chat model from the report's own
 * facts (AnalysisFacts) and checked before anything is shown (AnalysisValidator).
 *
 * On demand and stored: reading (stored()) never calls OpenAI; only an
 * admin's "Generate" does (generate()), and a range whose facts haven't
 * changed reuses its saved analysis instead of paying for a new one. Guards
 * around the paid call: a per-range lock (one call at a time), a 60-second
 * cooldown on "Regenerate", and DAILY_CAP calls a day across all admins. The
 * route adds throttle:6,1.
 *
 * The client is built lazily with a bounded Guzzle client (25 s, connect 5 s —
 * the default timeout is "wait forever", which would pin a PHP worker during an
 * OpenAI incident) and never with a missing key: without OPENAI_API_KEY the
 * card reports `unavailable`. No temperature or max_tokens — the configured
 * gpt-5 model accepts neither.
 */
final class ExecutiveAnalysisService
{
    public const DAILY_CAP = 40;

    public const REGENERATE_COOLDOWN = 60;

    public const FUNCTION = 'write_executive_analysis';

    private ?ClientContract $client = null;

    public function __construct(
        private readonly ExecutiveReportService $report,
        private readonly AnalysisFacts $facts,
        private readonly AnalysisValidator $validator,
    ) {}

    /** Use this client instead of building one (tests pass OpenAI's ClientFake). */
    public function withClient(ClientContract $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function available(): bool
    {
        return $this->client !== null || trim((string) config('services.openai.key')) !== '';
    }

    /** The saved analysis for a range, if any — never calls OpenAI. */
    public function stored(ReportRange $range, Carbon $now): array
    {
        $row = $this->latest($range);
        if ($row === null) {
            return ['status' => 'none', 'available' => $this->available()];
        }

        $current = AnalysisFacts::hash($this->facts->fromSummary($this->report->summary($range, $now)));

        return [
            'status' => 'ok',
            'available' => $this->available(),
            'stale' => $row->facts_hash !== $current,
            'analysis' => $this->present($row),
        ];
    }

    /**
     * Write (or reuse) the analysis for a range.
     *
     * @return array{status: 'ok'|'unavailable'|'error'|'busy'|'limited', ...}
     */
    public function generate(ReportRange $range, Carbon $now, ?int $userId, bool $regenerate = false): array
    {
        if (! $this->available()) {
            return ['status' => 'unavailable'];
        }

        $facts = $this->facts->fromSummary($this->report->summary($range, $now));
        $hash = AnalysisFacts::hash($facts);
        $existing = $this->latest($range);

        if ($existing !== null && ! $regenerate && $existing->facts_hash === $hash) {
            return ['status' => 'ok', 'stale' => false, 'reused' => true, 'analysis' => $this->present($existing)];
        }
        if ($existing !== null && $regenerate) {
            $wait = self::REGENERATE_COOLDOWN - ($now->getTimestamp() - Carbon::parse($existing->created_at, 'UTC')->getTimestamp());
            if ($wait > 0) {
                return ['status' => 'ok', 'stale' => $existing->facts_hash !== $hash, 'cooldown' => $wait, 'analysis' => $this->present($existing)];
            }
        }

        $lock = Cache::lock('exec-report:v1:analysis:'.$range->key(), 90);
        if (! $lock->get()) {
            return ['status' => 'busy'];
        }

        try {
            $day = 'exec-report:v1:analysis-calls:'.ReportRange::today($now);
            Cache::add($day, 0, 172800);
            if (Cache::increment($day) > self::DAILY_CAP) {
                return ['status' => 'limited'];
            }

            return $this->call($range, $now, $userId, $facts, $hash);
        } finally {
            $lock->release();
        }
    }

    private function call(ReportRange $range, Carbon $now, ?int $userId, array $facts, string $hash): array
    {
        $answer = $this->ask($range, $facts);
        if ($answer['status'] !== 'ok') {
            return $answer;
        }

        $stamp = $now->copy()->utc()->format('Y-m-d H:i:s');
        $id = DB::table('executive_report_analyses')->insertGetId([
            'date_start' => $range->start,
            'date_end' => $range->end,
            'facts_hash' => $hash,
            'facts' => json_encode($facts),
            'result' => json_encode($answer['result']),
            'model' => mb_substr($answer['model'], 0, 64),
            'prompt_tokens' => $answer['prompt_tokens'],
            'completion_tokens' => $answer['completion_tokens'],
            'duration_ms' => $answer['duration_ms'],
            'user_id' => $userId,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return ['status' => 'ok', 'stale' => false, 'analysis' => $this->present(DB::table('executive_report_analyses')->find($id))];
    }

    /**
     * One call to the model, validated — nothing stored, no cap counted (the
     * preview command uses this directly).
     *
     * @return array{status: 'ok', result: array, model: string, prompt_tokens: ?int, completion_tokens: ?int, duration_ms: int}|array{status: 'error', message: string}
     */
    public function ask(ReportRange $range, array $facts): array
    {
        $model = (string) config('services.openai.chat_model', 'gpt-5.4-mini');
        $started = hrtime(true);

        try {
            $response = $this->client()->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->instructions($facts)],
                    ['role' => 'user', 'content' => "FACTS\n".$this->factsBlock($facts)],
                ],
                'functions' => [$this->functionDefinition()],
                'function_call' => ['name' => self::FUNCTION],
            ]);
        } catch (\Throwable $e) {
            Log::warning('openai.executive_report.failed', ['range' => $range->key(), 'error' => $e->getMessage()]);

            return ['status' => 'error', 'message' => 'The AI service did not answer. Try again in a moment.'];
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1e6);
        $usage = $response->usage ?? null;
        Log::info('openai.executive_report', [
            'model' => $response->model ?? $model,
            'prompt_tokens' => $usage?->promptTokens,
            'completion_tokens' => $usage?->completionTokens,
            'total_tokens' => $usage?->totalTokens,
            'duration_ms' => $durationMs,
        ]);

        $raw = $this->arguments($response);
        $result = $raw !== null ? $this->validator->validate($raw, $facts) : null;
        if ($result !== null && $result['rejected'] !== []) {
            // What the checks threw away, and why — the record to tune the prompt by.
            Log::info('openai.executive_report.dropped', ['range' => $range->key(), 'rejected' => $result['rejected']]);
        }
        if ($result === null) {
            Log::warning('openai.executive_report.rejected', ['range' => $range->key(), 'raw' => mb_substr(json_encode($raw) ?: '', 0, 2000)]);

            return ['status' => 'error', 'message' => 'The AI\'s answer didn\'t pass the number checks, so it wasn\'t shown. Try again.'];
        }

        return [
            'status' => 'ok',
            'result' => $result,
            'model' => (string) ($response->model ?? $model),
            'prompt_tokens' => $usage?->promptTokens,
            'completion_tokens' => $usage?->completionTokens,
            'duration_ms' => $durationMs,
        ];
    }

    /** The facts the model would be given for a range (the preview command prints them). */
    public function factsFor(ReportRange $range, Carbon $now): array
    {
        return $this->facts->fromSummary($this->report->summary($range, $now));
    }

    private function client(): ClientContract
    {
        return $this->client ??= OpenAI::factory()
            ->withApiKey((string) config('services.openai.key'))
            ->withHttpClient(new Client(['timeout' => 25, 'connect_timeout' => 5]))
            ->make();
    }

    private function latest(ReportRange $range): ?object
    {
        return DB::table('executive_report_analyses')
            ->where('date_start', $range->start)
            ->where('date_end', $range->end)
            ->orderByDesc('id')
            ->first();
    }

    /** A saved row as the page shows it: the headline and bullets as segments over the facts they were written from. */
    public function present(object $row): array
    {
        $facts = json_decode($row->facts, true);
        $result = json_decode($row->result, true);

        return [
            'id' => (int) $row->id,
            'headline' => $result['headline'] !== null ? $this->validator->render($result['headline'], $facts) : null,
            'bullets' => array_map(fn ($b) => [
                'kind' => $b['kind'],
                'segments' => $this->validator->render($b['text'], $facts),
                'fact_ids' => $b['fact_ids'],
            ], $result['bullets']),
            'range' => $facts['meta']['range'],
            'previous' => $facts['meta']['previous'],
            'model' => $row->model,
            'generated_at' => Carbon::parse($row->created_at, 'UTC')->setTimezone(ReportRange::TZ)->toIso8601String(),
        ];
    }

    // ── The prompt ──────────────────────────────────────────────────────────

    public function instructions(array $facts): string
    {
        $range = $facts['meta']['range'];
        $previous = $facts['meta']['previous'];

        return <<<PROMPT
        You write the executive summary at the top of Filipino Homes' Executive Report, read by the CEO.
        Filipino Homes (FH) is a Philippine real-estate website: agents post listings, clients inquire
        through the FH chat, admins approve inquiries, and agents mark listings sold, rented or leased.

        The period is {$range['start']} to {$range['end']} (Manila). Where a fact has a previous value, it
        is for {$previous['start']} to {$previous['end']}.

        Write a headline (one sentence, under 140 characters) and three to six bullets.

        NUMBERS — the hard rule. Every number must come from the FACTS and be written ONLY as a placeholder:
          {id}         the fact's value          {id.prev}    its previous value
          {id.change}  its change in percent      {id.name}    the person, team or place it is about
        Never type a digit, a percent sign or a spelled-out number such as "zero", "three" or "twice". Use
        only ids listed in FACTS; {id.prev} and {id.change} only where the fact shows them. List the ids each
        bullet uses in fact_ids.

        Each placeholder turns into a BARE number ("387", "₱47,368", "−62%"), so the words next to it must say
        what it counts — follow each fact's "say:" wording. Then:
        - The current value is {id} and the earlier one {id.prev}: "from {id.prev} to {id}", never "to {id.prev}".
        - Use each placeholder once per bullet, and never list them back to back ("{a}, {b} and {c}").
        - A number is never the subject of a sentence: not "{a} fell", but "{a} listings were added, down {a.change}".
        GOOD: "{agents_dormant_30d} agents haven't opened FH in a month."
        GOOD: "Listings added fell {listings_added.change}, from {listings_added.prev} to {listings_added}."
        BAD:  "{inquiries_received}, {deals_total} and {claims_sold_unmarked} were all at nothing."
        BAD:  "{listings_added} fell to {listings_added.prev}."
        A bullet that breaks these rules is thrown away, so write fewer, cleaner bullets rather than more.

        WHAT TO SAY
        - Lead with what a CEO acts on: deals, whether clients get answers (hanging inquiries, reply
          speed), agents who are idle or dormant, listings an agent said were sold but left listed, and
          which regions, towns and teams drive the business.
        - kind: "win" for good news, "risk" for a problem, "watch" for something to keep an eye on,
          "action" for a concrete next step for management. Include at least one "action".
        - "as of now" facts are measured at this moment; "partial" facts start before FH kept that data, so
          they undercount and have no previous value. "lower is better" marks facts where a rise is bad.
        - Say only what the facts show. Don't guess at causes, and don't mention bookings, developers or
          closing prices — FH doesn't record them.
        - Plain, specific sentences; at most two per bullet. No markdown, links, emojis or hype.

        Return ONLY the function call.
        PROMPT;
    }

    public function factsBlock(array $facts): string
    {
        $lines = [];
        foreach ($facts['facts'] as $id => $f) {
            $parts = ["{{$id}}", $f['label'], 'value '.AnalysisValidator::format($f['value'], $f['unit']), "say: {$f['say']}"];
            if ($f['previous'] !== null) {
                $parts[] = 'previous '.AnalysisValidator::format($f['previous'], $f['unit']);
            }
            if ($f['change_pct'] !== null) {
                $parts[] = 'change '.AnalysisValidator::pct((float) $f['change_pct']);
            }
            if ($f['sample'] !== null) {
                $parts[] = "median of {$f['sample']}";
            }
            if (MetricRegistry::get($f['metric'])['better'] === 'down') {
                $parts[] = 'lower is better';
            }
            if ($f['as_of'] === 'now') {
                $parts[] = 'as of now';
            }
            if ($f['partial']) {
                $parts[] = 'partial';
            }
            if (isset($f['name'])) {
                $parts[] = "name: {$f['name']}";
            }
            $lines[] = implode(' | ', $parts);
        }

        return implode("\n", $lines);
    }

    private function functionDefinition(): array
    {
        return [
            'name' => self::FUNCTION,
            'description' => 'The executive summary of FH\'s performance for the period, every number as a placeholder.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'headline' => ['type' => 'string', 'description' => 'One sentence, under 140 characters.'],
                    'bullets' => [
                        'type' => 'array',
                        'minItems' => 3,
                        'maxItems' => 6,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => 'At most two sentences; numbers only as placeholders.'],
                                'fact_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'kind' => ['type' => 'string', 'enum' => AnalysisValidator::KINDS],
                            ],
                            'required' => ['text', 'fact_ids', 'kind'],
                        ],
                    ],
                ],
                'required' => ['headline', 'bullets'],
            ],
        ];
    }

    /**
     * The function call's arguments — or the same JSON sent as plain content,
     * which models sometimes do (the fallback ListingCommandService also takes).
     */
    private function arguments($response): ?array
    {
        $message = $response->choices[0]->message ?? null;
        $raw = $message?->functionCall?->arguments
            ?? ($message?->toolCalls[0] ?? null)?->function?->arguments
            ?? $message?->content;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode(trim(preg_replace('/^\s*```(?:json)?|```\s*$/m', '', trim($raw))), true);

        return is_array($decoded) ? $decoded : null;
    }
}
