<?php

namespace App\Console\Commands;

use App\Services\Reports\Executive\Analysis\AnalysisValidator;
use App\Services\Reports\Executive\Analysis\ExecutiveAnalysisService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Tune the Executive Report's AI analysis from the terminal: see exactly what
 * the model is given (--dry-run, no call), or ask it and read the bullets as
 * the page would show them. Nothing is stored unless --save, which makes it
 * the range's analysis on the page (as "Regenerate" would, minus the daily cap).
 */
class PreviewExecutiveAnalysis extends Command
{
    protected $signature = 'reports:executive-analysis
                            {start : First day, Y-m-d (Manila)}
                            {end : Last day, Y-m-d (Manila)}
                            {--dry-run : Print the instructions and the facts; don\'t call the model.}
                            {--save : Store the answer as the range\'s analysis.}';

    protected $description = 'Preview the Executive Report AI analysis for a date range.';

    public function handle(ExecutiveAnalysisService $ai, AnalysisValidator $validator): int
    {
        try {
            $range = ReportRange::resolve((string) $this->argument('start'), (string) $this->argument('end'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now = Carbon::now();
        $facts = $ai->factsFor($range, $now);

        if ($this->option('dry-run')) {
            $this->line($ai->instructions($facts));
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
            $result = $ai->generate($range, $now, null, regenerate: true);
            if ($result['status'] !== 'ok') {
                $this->error($result['status'].(isset($result['message']) ? ': '.$result['message'] : ''));

                return self::FAILURE;
            }
            $this->printAnalysis($result['analysis']['headline'], array_map(fn ($b) => [$b['kind'], $b['segments']], $result['analysis']['bullets']));
            $this->info('Saved as the analysis for '.$range->start.' → '.$range->end.'.');

            return self::SUCCESS;
        }

        $answer = $ai->ask($range, $facts);
        if ($answer['status'] !== 'ok') {
            $this->error($answer['message']);

            return self::FAILURE;
        }
        $result = $answer['result'];
        $this->printAnalysis(
            $result['headline'] !== null ? $validator->render($result['headline'], $facts) : null,
            array_map(fn ($b) => [$b['kind'], $validator->render($b['text'], $facts)], $result['bullets']),
        );
        $this->line(sprintf('%s · %d ms · %s prompt + %s completion tokens · %d bullet(s) dropped by the checks. Not saved.',
            $answer['model'], $answer['duration_ms'], $answer['prompt_tokens'] ?? '?', $answer['completion_tokens'] ?? '?', $result['dropped']));

        return self::SUCCESS;
    }

    private function printAnalysis(?array $headline, array $bullets): void
    {
        $plain = fn (array $segments) => implode('', array_map(fn ($s) => $s['type'] === 'metric' ? "[{$s['text']}]" : $s['text'], $segments));

        $this->newLine();
        if ($headline !== null) {
            $this->info($plain($headline));
        }
        foreach ($bullets as [$kind, $segments]) {
            $this->line(sprintf('  %-7s %s', strtoupper($kind), $plain($segments)));
        }
        $this->newLine();
    }
}
