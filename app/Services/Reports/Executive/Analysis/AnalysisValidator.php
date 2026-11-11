<?php

namespace App\Services\Reports\Executive\Analysis;

use App\Services\Reports\Executive\MetricRegistry;

/**
 * Keeps the AI analysis honest, then turns it into what the page renders.
 *
 * The model is told to write every number as a placeholder — {id} a fact's
 * value, {id.prev} its previous value, {id.change} its change, {id.name} the
 * person or place in it. validate() drops any bullet that still contains a
 * typed number (a digit, a "%", a spelled-out count), a placeholder for a fact
 * that doesn't exist or has nothing to show, markdown or a link. So whatever
 * the model says, every number on the page is one the report computed.
 *
 * render() swaps the placeholders for formatted values as segments; a value
 * segment carries its fact's metric, filters and dates, so the page can open
 * the records behind it like any other number in the report.
 */
final class AnalysisValidator
{
    public const KINDS = ['win', 'risk', 'watch', 'action'];

    public const MIN_BULLETS = 2;

    public const MAX_BULLETS = 6;

    private const PLACEHOLDER = '/\{([a-z][a-z0-9_]*)(?:\.(prev|change|name))?\}/';

    /** Counting words a model could slip a made-up number in with ("one" is too common to refuse). */
    private const NUMBER_WORDS = '/\b(?:zeros?|nil|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundreds?|thousands?|millions?|billions?|dozens?|percent|twice|double[ds]?|triple[ds]?|half|halved|quadrupled?)\b/i';

    /**
     * @param  array{meta: array, facts: array<string, array>}  $facts
     * @return array{headline: string|null, bullets: array<int, array{text: string, kind: string, fact_ids: string[]}>, dropped: int}|null
     *                                                                                                                                     null when fewer than MIN_BULLETS bullets survive
     */
    public function validate(array $raw, array $facts): ?array
    {
        $known = $facts['facts'];

        $headline = is_string($raw['headline'] ?? null) ? trim($raw['headline']) : '';
        $headline = $headline !== '' && mb_strlen($headline) <= 160 && $this->clean($headline, $known) ? $headline : null;

        $bullets = [];
        $dropped = 0;
        foreach (is_array($raw['bullets'] ?? null) ? $raw['bullets'] : [] as $bullet) {
            $text = is_array($bullet) && is_string($bullet['text'] ?? null) ? trim($bullet['text']) : '';
            $kind = is_array($bullet) ? ($bullet['kind'] ?? null) : null;

            if ($text === '' || mb_strlen($text) > 320 || ! in_array($kind, self::KINDS, true) || ! $this->clean($text, $known)
                || count($bullets) === self::MAX_BULLETS) {
                $dropped++;

                continue;
            }

            $named = array_filter((array) ($bullet['fact_ids'] ?? []), fn ($id) => is_string($id) && isset($known[$id]));
            $bullets[] = [
                'text' => $text,
                'kind' => $kind,
                'fact_ids' => array_values(array_unique([...array_column($this->placeholders($text), 0), ...$named])),
            ];
        }

        return count($bullets) < self::MIN_BULLETS ? null : ['headline' => $headline, 'bullets' => $bullets, 'dropped' => $dropped];
    }

    /** Only placeholder numbers, every placeholder resolvable, no markdown or links. */
    private function clean(string $text, array $known): bool
    {
        foreach ($this->placeholders($text) as [$id, $part]) {
            $fact = $known[$id] ?? null;
            $resolvable = $fact !== null && match ($part) {
                'name' => isset($fact['name']),
                'prev' => $fact['previous'] !== null,
                'change' => $fact['change_pct'] !== null,
                default => $fact['value'] !== null,
            };
            if (! $resolvable) {
                return false;
            }
        }

        $bare = preg_replace(self::PLACEHOLDER, '', $text) ?? '';

        return ! preg_match('/[0-9%{}]/', $bare)            // a typed number, or a broken placeholder
            && ! preg_match(self::NUMBER_WORDS, $bare)
            && ! preg_match('~https?://|www\.|\]\(|[*_#`|<>]~i', $bare);
    }

    /** @return array<int, array{0: string, 1: string}> [fact id, part ('' for the value)] */
    private function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches, PREG_SET_ORDER);

        return array_map(fn ($m) => [$m[1], $m[2] ?? ''], $matches);
    }

    /**
     * A validated text as segments: plain text, and the facts it names.
     *
     * @param  array{meta: array, facts: array<string, array>}  $facts
     */
    public function render(string $text, array $facts): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $segments = [];
        $offset = 0;
        foreach ($matches as $m) {
            [$whole, $at] = $m[0];
            if ($at > $offset) {
                $segments[] = ['type' => 'text', 'text' => substr($text, $offset, $at - $offset)];
            }
            $id = $m[1][0];
            $fact = $facts['facts'][$id];

            $segments[] = match ($m[2][0] ?? '') {
                'name' => ['type' => 'text', 'text' => (string) $fact['name']],
                'change' => [
                    'type' => 'change',
                    'text' => self::pct((float) $fact['change_pct']),
                    'direction' => $fact['change_pct'] > 0 ? 'up' : ($fact['change_pct'] < 0 ? 'down' : 'flat'),
                    'better' => MetricRegistry::get($fact['metric'])['better'],
                ],
                'prev' => self::metric($id, $fact, $fact['previous'], $facts['meta']['previous']),
                default => self::metric($id, $fact, $fact['value'], $facts['meta']['range']),
            };
            $offset = $at + strlen($whole);
        }
        if ($offset < strlen($text)) {
            $segments[] = ['type' => 'text', 'text' => substr($text, $offset)];
        }

        return $segments;
    }

    private static function metric(string $id, array $fact, int|float|null $value, array $range): array
    {
        return [
            'type' => 'metric',
            'text' => self::format($value, $fact['unit']),
            'fact' => $id,
            'metric' => $fact['metric'],
            'filters' => (object) $fact['filters'],
            'range' => $range,
        ];
    }

    /** A value as the report writes it: "1,204", "₱98,500", "2h 5m". */
    public static function format(int|float|null $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($unit) {
            'php_per_sqm' => '₱'.number_format((float) $value, 0),
            'minutes' => self::duration((float) $value),
            default => number_format((float) $value, floor((float) $value) == $value ? 0 : 1),
        };
    }

    public static function pct(float $pct): string
    {
        $sign = $pct > 0 ? '+' : ($pct < 0 ? '−' : '');

        return $sign.number_format(abs($pct), abs($pct) < 10 ? 1 : 0).'%';
    }

    private static function duration(float $minutes): string
    {
        $minutes = (int) round($minutes);
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60).'h'.($minutes % 60 ? ' '.($minutes % 60).'m' : '');
        }

        return number_format($minutes / 1440, 1).' days';
    }
}
