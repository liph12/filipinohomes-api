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
     * The latest search in this thread and what it has not shown yet, or null when the thread holds no
     * search at all (then the question is not about paging and the classifier decides).
     *
     * @return array{total: int, more: array<int, array{id: int, meters: int|null, landmark: string|null}>, ranked: string}|null
     */
    public function nextPage(Request $req): ?array
    {
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
            $more = is_array($meta['more'] ?? null) ? array_values($meta['more']) : [];

            return ['total' => (int) ($meta['total'] ?? 0), 'more' => $more, 'ranked' => (string) ($meta['ranked'] ?? 'fit')];
        }

        return null;
    }

    /**
     * @param  array{total: int, more: array<int, array{id: int, meters: int|null, landmark: string|null}>, ranked?: string}  $page
     */
    public function replyStream(array $page, Request $req): StreamedResponse
    {
        $total = max(0, (int) $page['total']);
        $more = $page['more'];
        $ranked = (string) ($page['ranked'] ?? 'fit');
        $order = ['nearest' => 'nearest first', 'drop' => 'biggest price cut first'][$ranked] ?? 'most complete first, then the best-priced for their area, then the newest';
        $batch = array_slice($more, 0, DataLayerService::SEARCH_PAGE);
        $rest = array_values(array_slice($more, count($batch)));

        $cards = [];
        foreach ($batch as $ref) {
            $id = (int) ($ref['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $card = $this->cardFor($id, isset($ref['meters']) ? (int) $ref['meters'] : null, isset($ref['landmark']) ? (string) $ref['landmark'] : null);
            if ($card !== null) {
                $cards[] = $card;
            }
        }
        $shownBefore = max(0, $total - count($more));

        if ($cards === []) {
            AsaQuestionLog::outcome($req, 'no_data');
            $text = $total > 0
                ? sprintf("Those were all **%d** that matched. Change the budget, the area or the type — for example **\"house and lot in Mandaue under ₱6M\"** — and I'll search again.", $total)
                : "There's nothing left to show from the last search. Tell me what you're after — budget, area and type — and I'll search again.";

            return $this->streamInsight('more', 'listing', ['suggested' => null, 'others' => [], 'total' => $total, 'more' => [], 'ranked' => $ranked], $text, [], $req);
        }

        $from = $shownBefore + 1;
        $to = $shownBefore + count($cards);
        $text = $rest === []
            ? sprintf('Here are the last **%d** of the **%d** matches (%d–%d). That is all of them — change the budget, the area or the type and I will search again.', count($cards), $total, $from, $to)
            : sprintf('Here are the next **%d** of the **%d** matches (%d–%d), in the same order — %s. **%d** more after these.', count($cards), $total, $from, $to, $order, count($rest));
        if ($rest === [] && count($cards) === 1) {
            $text = sprintf('Here is the last of the **%d** matches. That is all of them — change the budget, the area or the type and I will search again.', $total);
        }
        AsaQuestionLog::outcome($req, 'answered');

        return $this->streamInsight('more', 'listing', ['suggested' => null, 'others' => $cards, 'total' => $total, 'more' => $rest, 'ranked' => $ranked], $text, [], $req);
    }
}
