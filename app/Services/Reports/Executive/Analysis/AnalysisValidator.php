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
 * A placeholder becomes a BARE number, so it also drops the ways a model
 * garbles one even when the number itself is right: the same placeholder
 * twice, numbers listed back to back ("{a}, {b} and {c}"), a number as the
 * subject of a verb ("{a} fell"), a comparison the wrong way round ("to
 * {a.prev}"), and direction words that contradict the change ("rose
 * {a.change}" when it fell). Each refusal comes back with its reason.
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

    /** A value or previous-value placeholder — what the reader sees as a bare number. */
    private const NUMBER = '\{[a-z][a-z0-9_]*(?:\.prev)?\}';

    private const SUBJECT_VERBS = 'were|was|is|are|fell|rose|grew|dropped|stayed|remained|increased|decreased|declined|went|came|led|has|have|had|jumped|slipped|climbed|reached|hit';

    private const DOWN_WORDS = '/\b(?:fell|dropped|declined|decreased|down|lower|shrank|slipped|slid|less|fewer)\b/i';

    private const UP_WORDS = '/\b(?:rose|grew|increased|up|higher|jumped|climbed|gained|more)\b/i';

    /**
     * @param  array{meta: array, facts: array<string, array>}  $facts
     * @return array{headline: string|null, bullets: array<int, array{text: string, kind: string, fact_ids: string[]}>, dropped: int, rejected: array<int, array{text: string, reason: string}>}|null
     *                                                                                                                                                                                                null when fewer than MIN_BULLETS bullets survive
     */
    public function validate(array $raw, array $facts): ?array
    {
        $known = $facts['facts'];
        $rejected = [];

        $headline = is_string($raw['headline'] ?? null) ? trim($raw['headline']) : '';
        $problem = $headline === '' ? 'empty' : (mb_strlen($headline) > 160 ? 'too_long' : $this->problem($headline, $known));
        if ($problem !== null) {
            $rejected[] = ['text' => mb_substr($headline, 0, 320), 'reason' => "headline: {$problem}"];
            $headline = null;
        }

        $bullets = [];
        foreach (is_array($raw['bullets'] ?? null) ? $raw['bullets'] : [] as $bullet) {
            $text = is_array($bullet) && is_string($bullet['text'] ?? null) ? trim($bullet['text']) : '';
            // The card labels each bullet itself ("Next step:"), so a label the model added goes.
            $text = trim(preg_replace('/^(?:win|risk|watch|action|next step|good news|problem)\s*:\s*/i', '', $text) ?? $text);
            $kind = is_array($bullet) ? ($bullet['kind'] ?? null) : null;

            $problem = match (true) {
                $text === '' => 'empty',
                mb_strlen($text) > 320 => 'too_long',
                ! in_array($kind, self::KINDS, true) => 'kind',
                count($bullets) === self::MAX_BULLETS => 'too_many',
                default => $this->problem($text, $known),
            };
            if ($problem !== null) {
                $rejected[] = ['text' => mb_substr($text, 0, 320), 'reason' => $problem];

                continue;
            }

            $named = array_filter((array) ($bullet['fact_ids'] ?? []), fn ($id) => is_string($id) && isset($known[$id]));
            $bullets[] = [
                'text' => $text,
                'kind' => $kind,
                'fact_ids' => array_values(array_unique([...array_column($this->placeholders($text), 0), ...$named])),
            ];
        }

        $dropped = count(array_filter($rejected, fn ($r) => ! str_starts_with($r['reason'], 'headline')));

        return count($bullets) < self::MIN_BULLETS
            ? null
            : ['headline' => $headline, 'bullets' => $bullets, 'dropped' => $dropped, 'rejected' => $rejected];
    }

    /** Why a text can't be shown — null when it can. */
    private function problem(string $text, array $known): ?string
    {
        $placeholders = $this->placeholders($text);
        foreach ($placeholders as [$id, $part]) {
            $fact = $known[$id] ?? null;
            $resolvable = $fact !== null && match ($part) {
                'name' => isset($fact['name']),
                'prev' => $fact['previous'] !== null,
                'change' => $fact['change_pct'] !== null,
                default => $fact['value'] !== null,
            };
            if (! $resolvable) {
                return 'unknown_fact';
            }
        }

        $bare = preg_replace(self::PLACEHOLDER, '', $text) ?? '';
        if (preg_match('/[0-9%{}]/', $bare)) {
            return 'typed_number'; // or a broken placeholder
        }
        if (preg_match(self::NUMBER_WORDS, $bare)) {
            return 'number_word';
        }
        if (preg_match('~https?://|www\.|\]\(|[*_#`|<>]~i', $bare)) {
            return 'markup';
        }

        // Each number once: "{a.prev} … {a.prev}" is a slip ({id.name} may repeat).
        $keys = array_map(fn ($p) => $p[0].'.'.$p[1], array_filter($placeholders, fn ($p) => $p[1] !== 'name'));
        if (count($keys) !== count(array_unique($keys))) {
            return 'repeated';
        }
        $n = self::NUMBER;
        if (preg_match("/{$n}\s*(?:,|and|or|&)\s*(?:and\s+)?{$n}/i", $text)) {
            return 'bare_list';
        }
        if (preg_match("/{$n}\s+(?:".self::SUBJECT_VERBS.')\b/i', $text)) {
            return 'number_as_subject';
        }
        if (preg_match('/\bto\s+\{[a-z0-9_]+\.prev\}/i', $text)) {
            return 'reversed';
        }
        foreach ($placeholders as [$id, $part]) {
            if ($part === '' && str_contains($text, "{{$id}.prev}") && preg_match('/\bfrom\s+\{'.preg_quote($id, '/').'\}/', $text)) {
                return 'reversed';
            }
            if ($part === 'change' && ($why = $this->contradicts($text, $id, (float) $known[$id]['change_pct'])) !== null) {
                return $why;
            }
        }

        return null;
    }

    /** "fell {a.change}" when it rose (or the reverse) — judged within the change's own clause. */
    private function contradicts(string $text, string $id, float $pct): ?string
    {
        $at = strpos($text, "{{$id}.change}");
        $clause = preg_split('/[,;.]/', substr($text, 0, (int) $at));
        $clause = (string) end($clause);

        if ($pct > 0 && preg_match(self::DOWN_WORDS, $clause)) {
            return 'contradicts_change';
        }
        if ($pct < 0 && preg_match(self::UP_WORDS, $clause)) {
            return 'contradicts_change';
        }

        return null;
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
                    // Arrow + size: reads right after "down" / "up", and the arrow keeps the direction.
                    'text' => self::arrowPct((float) $fact['change_pct']),
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

    /** "▲ 12%", "▼ 8.5%", "0%". */
    public static function arrowPct(float $pct): string
    {
        $arrow = $pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '');

        return $arrow.number_format(abs($pct), abs($pct) < 10 ? 1 : 0).'%';
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
