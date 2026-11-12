<?php

namespace App\Services\Reports\Executive\Analysis;

use App\Services\Reports\Executive\AgentReportService;
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
 * The AI Report's written analysis — a headline and a few findings on what
 * the period's numbers say (and, on the Agents page, three actions), written
 * by the chat model from the report's own facts (AnalysisFacts) and checked
 * before anything is shown (AnalysisValidator). One analysis per page
 * (`scope`: overview — the hub — or agents) and date range.
 *
 * Stored, never written on read: stored() never calls OpenAI; generate() does
 * — an admin's "Write" or the nightly run (writeNightly(), so the standard
 * ranges open with it ready). A range whose range-measured facts haven't
 * changed reuses its saved analysis instead of paying for a new one; "as of
 * now" figures move by the minute and don't count as a change. Guards around
 * the paid call: a per-range lock (one call at a time), a 60-second cooldown
 * on "Regenerate", and DAILY_CAP calls a day across all admins. The route
 * adds throttle:6,1.
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

    /** The pages with an analysis of their own. */
    public const SCOPES = ['overview', 'agents'];

    private ?ClientContract $client = null;

    public function __construct(
        private readonly ExecutiveReportService $report,
        private readonly AgentReportService $agents,
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

    /** The saved analysis for a page and range, if any — never calls OpenAI. */
    public function stored(ReportRange $range, Carbon $now, string $scope = 'overview'): array
    {
        $row = $this->latest($range, $scope);
        if ($row === null) {
            return ['status' => 'none', 'available' => $this->available()];
        }

        return [
            'status' => 'ok',
            'available' => $this->available(),
            'stale' => ! $this->sameFacts($row, $this->factsFor($range, $now, $scope)),
            'analysis' => $this->present($row),
        ];
    }

    /**
     * Write (or reuse) the analysis for a page and range.
     *
     * @return array{status: 'ok'|'unavailable'|'error'|'busy'|'limited', ...}
     */
    public function generate(ReportRange $range, Carbon $now, ?int $userId, bool $regenerate = false, string $scope = 'overview'): array
    {
        if (! $this->available()) {
            return ['status' => 'unavailable'];
        }

        $facts = $this->factsFor($range, $now, $scope);
        $existing = $this->latest($range, $scope);
        $same = $existing !== null && $this->sameFacts($existing, $facts);

        if ($existing !== null && ! $regenerate && $same) {
            return ['status' => 'ok', 'stale' => false, 'reused' => true, 'analysis' => $this->present($existing)];
        }
        if ($existing !== null && $regenerate) {
            $wait = self::REGENERATE_COOLDOWN - ($now->getTimestamp() - Carbon::parse($existing->created_at, 'UTC')->getTimestamp());
            if ($wait > 0) {
                return ['status' => 'ok', 'stale' => ! $same, 'cooldown' => $wait, 'analysis' => $this->present($existing)];
            }
        }

        $lock = Cache::lock('exec-report:v1:analysis:'.$range->key().($scope === 'overview' ? '' : ":{$scope}"), 90);
        if (! $lock->get()) {
            return ['status' => 'busy'];
        }

        try {
            $day = 'exec-report:v1:analysis-calls:'.ReportRange::today($now);
            Cache::add($day, 0, 172800);
            if (Cache::increment($day) > self::DAILY_CAP) {
                return ['status' => 'limited'];
            }

            return $this->call($range, $now, $userId, $facts, $scope);
        } finally {
            $lock->release();
        }
    }

    /**
     * The nightly run for one page and range: write it unless today's is
     * already written. A lock in the shared database (not the per-host file
     * cache) keeps two scheduler hosts from both paying for it.
     *
     * @return string what happened: written | reused | already | locked | unavailable | error | busy | limited
     */
    public function writeNightly(ReportRange $range, Carbon $now, string $scope): string
    {
        if (! $this->available()) {
            return 'unavailable';
        }

        $lock = Cache::store('database')->lock("exec-report:nightly:{$scope}:{$range->key()}:".ReportRange::today($now), 900);
        if (! $lock->get()) {
            return 'locked';
        }

        try {
            $midnight = Carbon::parse(ReportRange::today($now), ReportRange::TZ)->startOfDay()->utc()->format('Y-m-d H:i:s');
            $written = DB::table('executive_report_analyses')
                ->where('scope', $scope)
                ->where('date_start', $range->start)
                ->where('date_end', $range->end)
                ->where('created_at', '>=', $midnight)
                ->exists();
            if ($written) {
                return 'already';
            }

            $result = $this->generate($range, $now, null, false, $scope);
            if ($result['status'] !== 'ok') {
                Log::warning('openai.executive_report.nightly', ['scope' => $scope, 'range' => $range->key(), 'status' => $result['status'], 'message' => $result['message'] ?? null]);

                return $result['status'];
            }

            return ($result['reused'] ?? false) ? 'reused' : 'written';
        } finally {
            $lock->release();
        }
    }

    private function call(ReportRange $range, Carbon $now, ?int $userId, array $facts, string $scope): array
    {
        $answer = $this->ask($range, $facts, $scope);
        if ($answer['status'] !== 'ok') {
            return $answer;
        }

        $stamp = $now->copy()->utc()->format('Y-m-d H:i:s');
        $id = DB::table('executive_report_analyses')->insertGetId([
            'scope' => $scope,
            'date_start' => $range->start,
            'date_end' => $range->end,
            'facts_hash' => AnalysisFacts::hash($facts),
            'stable_hash' => AnalysisFacts::stableHash($facts),
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
    public function ask(ReportRange $range, array $facts, string $scope = 'overview'): array
    {
        $model = (string) config('services.openai.chat_model', 'gpt-5.4-mini');
        $started = hrtime(true);

        try {
            $response = $this->client()->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->instructions($facts, $scope)],
                    ['role' => 'user', 'content' => "FACTS\n".$this->factsBlock($facts)],
                ],
                'functions' => [$this->functionDefinition($scope)],
                'function_call' => ['name' => self::FUNCTION],
            ]);
        } catch (\Throwable $e) {
            Log::warning('openai.executive_report.failed', ['scope' => $scope, 'range' => $range->key(), 'error' => $e->getMessage()]);

            return ['status' => 'error', 'message' => 'The AI service did not answer. Try again in a moment.'];
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1e6);
        $usage = $response->usage ?? null;
        Log::info('openai.executive_report', [
            'scope' => $scope,
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
            Log::info('openai.executive_report.dropped', ['scope' => $scope, 'range' => $range->key(), 'rejected' => $result['rejected']]);
        }
        if ($result === null) {
            Log::warning('openai.executive_report.rejected', ['scope' => $scope, 'range' => $range->key(), 'raw' => mb_substr(json_encode($raw) ?: '', 0, 2000)]);

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

    /** The facts the model would be given for a page and range (the preview command prints them). */
    public function factsFor(ReportRange $range, Carbon $now, string $scope = 'overview'): array
    {
        return match ($scope) {
            'agents' => $this->facts->fromAgents($this->agents->page($range, $now)),
            default => $this->facts->fromSummary($this->report->summary($range, $now)),
        };
    }

    /** Whether a saved analysis was written from the same range-measured facts (older rows: the same facts). */
    private function sameFacts(object $row, array $facts): bool
    {
        return $row->stable_hash !== null
            ? $row->stable_hash === AnalysisFacts::stableHash($facts)
            : $row->facts_hash === AnalysisFacts::hash($facts);
    }

    private function client(): ClientContract
    {
        return $this->client ??= OpenAI::factory()
            ->withApiKey((string) config('services.openai.key'))
            ->withHttpClient(new Client(['timeout' => 25, 'connect_timeout' => 5]))
            ->make();
    }

    private function latest(ReportRange $range, string $scope): ?object
    {
        return DB::table('executive_report_analyses')
            ->where('scope', $scope)
            ->where('date_start', $range->start)
            ->where('date_end', $range->end)
            ->orderByDesc('id')
            ->first();
    }

    /** A saved row as the page shows it: the headline, findings and actions as segments over the facts they were written from. */
    public function present(object $row): array
    {
        $facts = json_decode($row->facts, true);
        $result = json_decode($row->result, true);

        return [
            'id' => (int) $row->id,
            'scope' => $row->scope ?? 'overview',
            'headline' => $result['headline'] !== null ? $this->validator->render($result['headline'], $facts) : null,
            'bullets' => array_map(fn ($b) => [
                'kind' => $b['kind'],
                'segments' => $this->validator->render($b['text'], $facts),
                'fact_ids' => $b['fact_ids'],
            ], $result['bullets']),
            'actions' => array_map(function ($a) use ($facts) {
                // The list the step needs: its first fact that has one.
                $open = collect($a['fact_ids'])->map(fn ($id) => $facts['facts'][$id] ?? null)->first(fn ($f) => $f !== null && $f['metric'] !== null);

                return [
                    'title' => $a['title'],
                    'segments' => $this->validator->render($a['text'], $facts),
                    'fact_ids' => $a['fact_ids'],
                    'open' => $open === null ? null : ['metric' => $open['metric'], 'filters' => (object) $open['filters'], 'label' => $open['label']],
                ];
            }, $result['actions'] ?? []),
            'range' => $facts['meta']['range'],
            'previous' => $facts['meta']['previous'],
            'model' => $row->model,
            'written_by' => $row->user_id === null ? 'nightly' : 'admin',
            'generated_at' => Carbon::parse($row->created_at, 'UTC')->setTimezone(ReportRange::TZ)->toIso8601String(),
        ];
    }

    // ── The prompt ──────────────────────────────────────────────────────────

    public function instructions(array $facts, string $scope = 'overview'): string
    {
        $range = $facts['meta']['range'];
        $previous = $facts['meta']['previous'];
        $numbers = self::numberRules();

        if ($scope === 'agents') {
            return <<<PROMPT
            You write the summary at the top of the Agents page of Filipino Homes' AI Report, read by the CEO and
            managers — many of them over forty, so write plainly: short sentences, everyday words, no jargon.
            Filipino Homes (FH) is a Philippine real-estate website. Agents post listings; clients reach them by
            asking about a listing in the FH chat, messaging the agent directly, sending a Buyer & Seller Link brief
            or registering at an open house. An agent is "working" when they did something recorded on FH.

            The period is {$range['start']} to {$range['end']} (Manila). Where a fact has a previous value, it is for
            {$previous['start']} to {$previous['end']}.

            Write a headline (one sentence, under 140 characters), three to five findings, and exactly three actions.

            {$numbers}
            A "percent" fact already carries its % sign when shown — write {id}, never "{id}%".

            WHAT TO SAY
            - Lead with what management acts on: whether clients' leads get answered and how fast, leads that go to
              agents who don't use FH or who have no team, Cebu and the other office regions, the most and least
              active teams, agents who stopped, and agents who never used FH.
            - findings: kind "win" for good news, "risk" for a problem, "watch" for something to keep an eye on.
            - Prefer facts that aren't zero. Write about a zero only when the zero is itself the problem (no lead
              answered, a team with nobody working).
            - actions: three concrete steps for this week. Each has a title — a plain imperative of a few words with
              no numbers ("Call back the clients nobody answered") — and a text of at most two sentences saying who
              should do what. When a step is about one team, office region or agent, name it with {id.name}. List
              in fact_ids the facts whose lists the step needs; the page opens the first one.
            - "as of now" facts are measured at this moment; "partial" facts start before FH kept that data, so
              they undercount and have no previous value. "lower is better" marks facts where a rise is bad.
            - Say only what the facts show. Don't guess at causes. Only replies in the FH chat are seen — a lead
              can be answered by phone — so say "not answered on FH", not "ignored".
            - Plain, specific sentences; at most two per finding. No markdown, links, emojis or hype.

            Return ONLY the function call.
            PROMPT;
        }

        return <<<PROMPT
        You write the executive summary at the top of Filipino Homes' AI Report, read by the CEO.
        Filipino Homes (FH) is a Philippine real-estate website: agents post listings, clients inquire
        through the FH chat, admins approve inquiries, and agents mark listings sold, rented or leased.

        The period is {$range['start']} to {$range['end']} (Manila). Where a fact has a previous value, it
        is for {$previous['start']} to {$previous['end']}.

        Write a headline (one sentence, under 140 characters) and three to six bullets.

        {$numbers}

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

    /** The hard rule both prompts share: every number is a placeholder. */
    private static function numberRules(): string
    {
        return <<<'RULES'
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
        RULES;
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
            if (($f['better'] ?? ($f['metric'] !== null ? MetricRegistry::get($f['metric'])['better'] : null)) === 'down') {
                $parts[] = 'lower is better';
            }
            if ($f['unit'] === 'percent') {
                $parts[] = 'percent';
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

    private function functionDefinition(string $scope = 'overview'): array
    {
        $item = fn (array $properties, array $required) => ['type' => 'object', 'properties' => $properties, 'required' => $required];
        $properties = [
            'headline' => ['type' => 'string', 'description' => 'One sentence, under 140 characters.'],
            'bullets' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => $scope === 'agents' ? 5 : 6,
                'items' => $item([
                    'text' => ['type' => 'string', 'description' => 'At most two sentences; numbers only as placeholders.'],
                    'fact_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'kind' => ['type' => 'string', 'enum' => $scope === 'agents' ? ['win', 'risk', 'watch'] : AnalysisValidator::KINDS],
                ], ['text', 'fact_ids', 'kind']),
            ],
        ];
        $required = ['headline', 'bullets'];
        if ($scope === 'agents') {
            $properties['actions'] = [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 3,
                'items' => $item([
                    'title' => ['type' => 'string', 'description' => 'A plain imperative of a few words, no numbers.'],
                    'text' => ['type' => 'string', 'description' => 'At most two sentences: who should do what; numbers only as placeholders.'],
                    'fact_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], ['title', 'text', 'fact_ids']),
            ];
            $required[] = 'actions';
        }

        return [
            'name' => self::FUNCTION,
            'description' => $scope === 'agents'
                ? 'The summary of the Agents page for the period — findings and three actions, every number as a placeholder.'
                : 'The executive summary of FH\'s performance for the period, every number as a placeholder.',
            'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
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
