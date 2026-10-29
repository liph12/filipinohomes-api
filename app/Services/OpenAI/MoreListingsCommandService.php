<?php

namespace App\Services\OpenAI;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Show more" after a listing search: the next page of the matches that search already ranked.
 *
 * A search keeps the ranked rest of its matches (ids, with distances when it measured from a place)
 * on the cards it stored in the day's thread; each page here takes the next SEARCH_PAGE of them,
 * draws the cards afresh, and stores what is still left. No model and no new query are involved,
 * so page two is the same ranking as page one, just further down.
 */
class MoreListingsCommandService extends CacheService
{
    use InsightStream;

    /** "Show more", "more options", "next 5", "see the rest", "what else do you have", "any more?". */
    public const PATTERN = '/^\s*(?:(?:please\s+)?(?:show|see|give|load|view|get|display)\s+(?:me\s+)?(?:the\s+)?(?:\d+\s+)?(?:more|next|other|remaining|rest)(?:\s+(?:of\s+(?:the|them)\s*)?(?:options?|listings?|properties|results?|homes?|houses?|condos?|units?|lots?|matches|choices?|ones?|please))?|(?:\d+\s+)?more(?:\s+(?:options?|listings?|properties|results?|please|homes?|houses?|condos?|units?|lots?))?|next(?:\s+(?:\d+|page|batch|ones?))?|what\s+else(?:\s+(?:do\s+you\s+have|is\s+there|have\s+you\s+got))?|any(?:thing)?\s+(?:more|else|others?)|others?)\s*[.!?]*\s*$/iu';

    /**
     * The latest search and what it has not shown yet: from the request's `page` (the chat sends the
     * latest search's paging state with every message, so paging survives a thread cache that is not
     * shared between app servers), else from the day's thread. Null when there is no search at all —
     * then the question is not about paging and the classifier decides.
     *
     * @return array{total: int, shown: int, more: array<int, array{id: int, meters: int|null, landmark: string|null}>, ranked: string}|null
     */
    public function nextPage(Request $req): ?array
    {
        $sent = $req->input('page');
        if (is_array($sent) && is_array($sent['more'] ?? null)) {
            return self::pageFrom($sent);
        }
        try {
            $messages = $this->getDailyMessages($req);
        } catch (\Throwable) {
            return null;
        }
        foreach (array_reverse($messages) as $m) {
            $meta = $m['metaData']['listing'] ?? null;
            if (! is_array($meta)) {
                continue;
            }

            return self::pageFrom($meta);
        }

        return null;
    }

    /**
     * Paging state from stored or sent metadata, sanitised: ids are integers, at most the pool, and the
     * cards are drawn afresh from public listings, so a tampered list can show nothing it should not.
     *
     * @return array{total: int, shown: int, more: array<int, array{id: int, meters: int|null, landmark: string|null}>, ranked: string}
     */
    /** A ranking label the search could have produced: fit, nearest, drop, or "facilities:mall,school". */
    private static function rankedFrom($value): string
    {
        if (in_array($value, ['fit', 'nearest', 'drop'], true)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^facilities:([a-z_]+(?:,[a-z_]+){0,2})$/', $value, $m)
            && array_diff(explode(',', $m[1]), \App\Services\Listing\FacilityIndex::CATEGORIES) === []) {
            return $value;
        }

        return 'fit';
    }

    private static function pageFrom(array $meta): array
    {
        $more = [];
        foreach (array_slice(array_values((array) ($meta['more'] ?? [])), 0, DataLayerService::SEARCH_POOL) as $ref) {
            $id = (int) (is_array($ref) ? ($ref['id'] ?? 0) : $ref);
            if ($id <= 0) {
                continue;
            }
            $more[] = [
                'id' => $id,
                'meters' => is_array($ref) && isset($ref['meters']) && is_numeric($ref['meters']) ? (int) $ref['meters'] : null,
                'landmark' => is_array($ref) && isset($ref['landmark']) && is_string($ref['landmark']) ? mb_substr($ref['landmark'], 0, 120) : null,
                'nearby' => is_array($ref) && is_array($ref['nearby'] ?? null) ? array_slice(array_values(array_filter($ref['nearby'], 'is_array')), 0, 3) : null,
            ];
        }
        $total = max(0, (int) ($meta['total'] ?? 0));
        $shown = isset($meta['shown']) && is_numeric($meta['shown']) ? max(0, (int) $meta['shown']) : max(0, min($total, $total - count($more)));
        $ranked = self::rankedFrom($meta['ranked'] ?? null);

        return ['total' => $total, 'shown' => $shown, 'more' => $more, 'ranked' => $ranked];
    }

    /**
     * @param  array{total: int, shown?: int, more: array<int, array{id: int, meters: int|null, landmark: string|null}>, ranked?: string}  $page
     */
    public function replyStream(array $page, Request $req): StreamedResponse
    {
        $total = max(0, (int) $page['total']);
        $more = $page['more'];
        $shownBefore = isset($page['shown']) ? max(0, (int) $page['shown']) : max(0, $total - count($more));
        $ranked = (string) ($page['ranked'] ?? 'fit');
        $order = ['nearest' => 'nearest first', 'drop' => 'biggest price cut first'][$ranked] ?? (str_starts_with($ranked, 'facilities:')
            ? 'closest to '.implode(' and ', array_map(fn ($k) => 'a '.(\App\Services\Listing\FacilityIndex::SINGULAR[$k] ?? $k), explode(',', substr($ranked, 11)))).' first'
            : 'most complete first, then the best-priced for their area, then the newest');
        $batch = array_slice($more, 0, DataLayerService::SEARCH_PAGE);
        $rest = array_values(array_slice($more, count($batch)));

        $cards = [];
        foreach ($batch as $ref) {
            $id = (int) ($ref['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $card = $this->cardFor($id, isset($ref['meters']) ? (int) $ref['meters'] : null, isset($ref['landmark']) ? (string) $ref['landmark'] : null, isset($ref['nearby']) && is_array($ref['nearby']) ? $ref['nearby'] : null);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        if ($cards === []) {
            AsaQuestionLog::outcome($req, 'no_data');
            $text = $total > $shownBefore && $shownBefore > 0
                ? sprintf("You have seen the **%d** best-ranked of the **%d** that matched; the rest are not ranked. Narrow the search — a barangay, a tighter budget or a bedroom count — and I'll rank them again.", $shownBefore, $total)
                : ($total > 0
                    ? sprintf("Those were all **%d** that matched. Change the budget, the area or the type — for example **\"house and lot in Mandaue under ₱6M\"** — and I'll search again.", $total)
                    : "There's nothing left to show from the last search. Tell me what you're after — budget, area and type — and I'll search again.");

            return $this->streamInsight('more', 'listing', ['suggested' => null, 'others' => [], 'total' => $total, 'shown' => $shownBefore, 'more' => [], 'ranked' => $ranked], $text, [], $req);
        }

        $from = $shownBefore + 1;
        $to = $shownBefore + count($cards);
        if ($rest !== []) {
            $text = sprintf('Here are the next **%d** of the **%d** matches (%d–%d), in the same order — %s. **%d** more after these.', count($cards), $total, $from, $to, $order, count($rest));
        } elseif ($total > $to) {
            // The ranked pool ran out before the matches did: say so instead of "that is all".
            $text = sprintf('Here are the last of the **%d** best-ranked (%d–%d); **%d** more matched beyond them. Narrow the search — a barangay, a tighter budget or a bedroom count — and I will rank them again.', $to, $from, $to, $total - $to);
        } elseif (count($cards) === 1) {
            $text = sprintf('Here is the last of the **%d** matches. That is all of them — change the budget, the area or the type and I will search again.', $total);
        } else {
            $text = sprintf('Here are the last **%d** of the **%d** matches (%d–%d). That is all of them — change the budget, the area or the type and I will search again.', count($cards), $total, $from, $to);
        }
        AsaQuestionLog::outcome($req, 'answered');

        return $this->streamInsight('more', 'listing', ['suggested' => null, 'others' => $cards, 'total' => $total, 'shown' => $to, 'more' => $rest, 'ranked' => $ranked], $text, [], $req);
    }
}
