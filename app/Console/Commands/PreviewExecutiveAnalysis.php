<?php

namespace App\Console\Commands;

use App\Services\Reports\Executive\Analysis\AnalysisValidator;
use App\Services\Reports\Executive\Analysis\ExecutiveAnalysisService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Tune the AI Report's written analysis from the terminal: see exactly what
 * the model is given (--dry-run, no call), or ask it and read the findings
 * (and, for the Agents page, the actions) as the page would show them.
 * Nothing is stored unless --save, which makes it the page's analysis for the
 * range (as "Regenerate" would, minus the daily cap).
 */
class PreviewExecutiveAnalysis extends Command
{
    protected $signature = 'reports:executive-analysis
                            {start : First day, Y-m-d (Manila)}
                            {end : Last day, Y-m-d (Manila)}
                            {--scope=overview : The page — overview (the hub) or agents}
                            {--dry-run : Print the instructions and the facts; don\'t call the model.}
                            {--save : Store the answer as the range\'s analysis.}';

    protected $description = 'Preview the AI Report analysis for a page and date range.';

    public function handle(ExecutiveAnalysisService $ai, AnalysisValidator $validator): int
    {
        $scope = (string) $this->option('scope');
        if (! in_array($scope, ExecutiveAnalysisService::SCOPES, true)) {
            $this->error('--scope is one of: '.implode(', ', ExecutiveAnalysisService::SCOPES).'.');

            return self::FAILURE;
        }
        try {
            $range = ReportRange::resolve((string) $this->argument('start'), (string) $this->argument('end'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now = Carbon::now();
        $facts = $ai->factsFor($range, $now, $scope);

        if ($this->option('dry-run')) {
            $this->line($ai->instructions($facts, $scope));
            $this->newLine();
            $this->line("FACTS\n".$ai->factsBlock($facts));
            $this->newLine();
            $this->info(count($facts['facts']).' facts. Dry run — the model was not called.');

            return self::SUCCESS;
        }

        if (! $ai->available()) {
            $this->error('OPENAI_API_KEY is not set.');

            return self::FAILURE;
        }

        if ($this->option('save')) {
            $result = $ai->generate($range, $now, null, regenerate: true, scope: $scope);
            if ($result['status'] !== 'ok') {
                $this->error($result['status'].(isset($result['message']) ? ': '.$result['message'] : ''));

                return self::FAILURE;
            }
            $a = $result['analysis'];
            $this->printAnalysis(
                $a['headline'],
                array_map(fn ($b) => [$b['kind'], $b['segments']], $a['bullets']),
                array_map(fn ($x) => [$x['title'], $x['segments']], $a['actions']),
            );
            $this->info("Saved as the {$scope} analysis for {$range->start} → {$range->end}.");

            return self::SUCCESS;
        }

        $answer = $ai->ask($range, $facts, $scope);
        if ($answer['status'] !== 'ok') {
            $this->error($answer['message']);

            return self::FAILURE;
        }
        $result = $answer['result'];
        $this->printAnalysis(
            $result['headline'] !== null ? $validator->render($result['headline'], $facts) : null,
            array_map(fn ($b) => [$b['kind'], $validator->render($b['text'], $facts)], $result['bullets']),
            array_map(fn ($x) => [$x['title'], $validator->render($x['text'], $facts)], $result['actions']),
        );
        $this->line(sprintf('%s · %d ms · %s prompt + %s completion tokens · %d item(s) dropped by the checks. Not saved.',
            $answer['model'], $answer['duration_ms'], $answer['prompt_tokens'] ?? '?', $answer['completion_tokens'] ?? '?', $result['dropped']));

        return self::SUCCESS;
    }

    private function printAnalysis(?array $headline, array $bullets, array $actions = []): void
    {
        $plain = fn (array $segments) => implode('', array_map(fn ($s) => in_array($s['type'], ['metric', 'value'], true) ? "[{$s['text']}]" : $s['text'], $segments));

        $this->newLine();
        if ($headline !== null) {
            $this->info($plain($headline));
        }
        foreach ($bullets as [$kind, $segments]) {
            $this->line(sprintf('  %-7s %s', strtoupper($kind), $plain($segments)));
        }
        foreach ($actions as $i => [$title, $segments]) {
            $this->line(sprintf('  %d. %s — %s', $i + 1, $title, $plain($segments)));
        }
        $this->newLine();
    }
}
