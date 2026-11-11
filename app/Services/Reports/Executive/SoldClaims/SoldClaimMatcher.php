<?php

namespace App\Services\Reports\Executive\SoldClaims;

/**
 * Does this chat reply say the listing is already gone?
 *
 * Agents answer inquiries in English, Tagalog/Taglish, Cebuano/Bisaya and
 * Hiligaynon — "sold na po", "nahalin na", "nabaligya na", "no longer
 * available" — and often never mark the listing sold, so it keeps drawing
 * leads. This finds those replies. It is deliberately conservative: a false
 * "sold" on a listing that is still for sale is worse than a missed one, so
 * every match must survive the guards below.
 *
 * Tiers
 *   strong — unambiguous ("sold na", "already sold", "nahalin na", "taken na");
 *   medium — reserved / on hold ("reserved na", "may buyer na");
 *   weak   — kept to tune the lists, never counted: bare "sold" / "taken" /
 *            "wala na" in a short sentence about the property, a hedged claim
 *            ("baka sold na", "sold na yata"), or a rent phrase on a SALE
 *            listing, where "already rented" usually means it has a tenant.
 *
 * Guards — a candidate is dropped when, within its own clause (commas and
 * "but / pero / kaso / apan" end a clause):
 *   question     — the clause has a "?" or the particle "ba";
 *   negation     — "not / hindi / di / dili / indi / wala pa" in the four words
 *                  before it, or "yet" right after it;
 *   conditional  — "if / kung / kon / pag / kapag / inig / once" before it;
 *   other_unit   — another unit is named ("Phase 1", "the 2BR units", "yung
 *                  isa", "3 units", "next door");
 *   price        — money follows it ("sold at 4.5M", "for 3,500,000");
 *   off_topic    — it is about a promo, a viewing slot, a payment term…;
 *   person       — an availability phrase about a person ("I'm no longer
 *                  available", "dili na available si sir").
 * Phrases match only on word boundaries — a hyphen counts as part of the word
 * — so "soldier", "resold", "unsold", "pang-benta", the barangays Naghalin and
 * Guinhalinan, "mahalin" (to love) and the surname Macabenta never match.
 * "Gibaligya" (Cebuano: FOR sale) is deliberately absent. Hiligaynon "halin"
 * also means "from", so "halin na" is refused before "sa / kay / diri / didto".
 *
 * Bump VERSION whenever a list or guard changes: the scanner then re-reads
 * every message under the new rules.
 */
final class SoldClaimMatcher
{
    public const VERSION = 1;

    /**
     * In a phrase a space stands for "spaces or hyphens, optionally around a
     * politeness particle" ("hindi na po available", "dili na jud available")
     * and a hyphen for "an optional space or hyphen" ("na-sold" also matches
     * "nasold" and "na sold").
     */
    public const STRONG = [
        // English
        'already been sold', 'already sold', 'sold already', 'has been sold', 'was sold', 'got sold', 'sold-out',
        'already taken', 'no longer available', 'not available anymore', 'no longer for sale', 'off the market',
        'no more available', 'unavailable na', 'already unavailable', 'not available na',
        // Tagalog / Taglish
        'na-sold', 'sold na', 'nai-benta', 'na-benta', 'benta na', 'nabili na', 'binili na', 'may nakabili na',
        'may bumili na', 'taken na', 'hindi na available', 'di na available', 'wala nang available',
        'wala ng available', 'wala na available',
        // Cebuano / Bisaya
        'na-halin', 'halin na', 'na-baligya', 'naa nay nakapalit', 'naa nay nipalit', 'naa nay mipalit',
        'naay nakapalit', 'gipalit na', 'dili na available', 'wala nay available',
        // Hiligaynon
        'na-bakal', 'indi na available',
        // Rent — see RENTAL
        'already rented', 'rented already', 'rented na', 'na-rent na', 'narentahan na', 'already leased',
        'may umupa na', 'may nangupahan na', 'naa nay ni-rent', 'naa nay nag-rent', 'giabangan na', 'naabangan na',
    ];

    public const MEDIUM = [
        'already reserved', 'under reservation', 'reserved na', 'naka-reserve na', 'na-reserve na',
        'may reservation na', 'naa nay reservation', 'may nagpa-reserve na', 'may nag-reserve na',
        'naa nay nag-reserve', 'naa nay nagpa-reserve', 'on hold na', 'already on hold',
        'may buyer na', 'naa nay buyer', 'may nakakuha na', 'may kumuha na',
    ];

    public const WEAK = ['sold', 'taken', 'wala na', 'reserved'];

    /**
     * Rent phrases. On a For Rent listing they are a strong claim; on a sale
     * listing "already rented" usually sells it as an investment ("already
     * rented, 20k a month"), so there they only ever count as weak.
     */
    public const RENTAL = [
        'already rented', 'rented already', 'rented na', 'na-rent na', 'narentahan na', 'already leased',
        'may umupa na', 'may nangupahan na', 'naa nay ni-rent', 'naa nay nag-rent', 'giabangan na', 'naabangan na',
    ];

    /**
     * Phrases that also describe things that aren't the property — a time
     * slot, a promo, a person — so they get the "person" guard on top of the
     * off-topic one every phrase gets.
     */
    private const GENERIC = [
        'no longer available', 'not available anymore', 'no more available', 'unavailable na', 'already unavailable',
        'not available na', 'hindi na available', 'di na available', 'wala nang available', 'wala ng available',
        'wala na available', 'dili na available', 'wala nay available', 'indi na available',
        'already taken', 'taken na', 'taken', 'reserved', ...self::MEDIUM,
    ];

    private const PARTICLES = 'po|pong|ho|hong|man|gyud|jud|pud|sad|lang|talaga';

    /** Cheap gate: a message with none of these stems can't match anything. */
    private const PREFILTER = '/sold|bent|bili|halin|baligya|palit|bakal|rent|leas|taken|availab|reserv|buyer|kuha|market|wala|hold|upa|abang/u';

    private const CLAUSE_BREAK = '/[,;:]|(?<![\p{L}\p{N}])(?:but|pero|kaso|apan|however|although|though)(?![\p{L}\p{N}])/u';

    private const NEGATORS = ['not', 'never', 'hindi', 'di', 'dili', 'indi', 'wala', 'wla', 'wa'];

    private const CONDITIONALS = ['if', 'kung', 'kon', 'pag', 'kapag', 'inig', 'once', 'whether', 'incase'];

    // Not "kaya": it is far more often "so / therefore" than "I wonder".
    private const HEDGES = ['maybe', 'baka', 'basin', 'perhaps', 'possibly', 'probably', 'siguro', 'guro', 'ata', 'yata', 'seems', 'think', 'believe'];

    private const OTHER_UNIT_BEFORE = '/(?:^|\s)(?:'
        .'(?:phase|tower|bldg|building|cluster|wing)\s*(?:no\.?|#)?\s*(?:\d+[a-z]?|[a-z]|one|two|three|four|five|i{1,3}|iv|v)'
        .'|(?:other|another|ibang|lain|kabilang|ubang)\s+(?:one|ones|unit|units|lot|lots|house|houses|bahay|balay|listing|listings|property|properties)'
        .'|yung\s+iba|ang\s+uban|the\s+others|the\s+rest|others'
        .'|(?:\d\s*-?\s*(?:br|bedroom|bed)|studio|penthouse|loft)s?\s+(?:units|types)'
        .')(?=\s|$)/u';

    private const OTHER_UNIT_AFTER = '/^(?:(?:na|po|out)\s+)*(?:'
        .'(?:yung|ang|the|sa)\s+(?:katabi|tupad|isa|iba|uban|usa|other|others|rest)'
        .'|next\s+door'
        .'|(?:\d+|many|several|maraming|daghan)\s+(?:units|lots|houses|properties|condos)'
        .'|(?:(?:yung|ang|the|sa)\s+)?(?:phase|tower|bldg|building|cluster|wing)\s*(?:no\.?|#)?\s*(?:\d+[a-z]?|[a-z]|one|two|three|i{1,3}|iv|v)'
        .')(?=\s|$)/u';

    private const MONEY = '(?:(?:₱|php|p)\s?\d|\d[\d,.]*\s*(?:m|k|mil|million|thousand|pesos)(?![\p{L}\p{N}])|\d{1,3}(?:,\d{3})+|\d{4,})';

    private const PRICE_AFTER = '/^[\s,:]*(?:(?:at|for|sa|ng|@|worth|around|about|only)\s*){0,2}'.self::MONEY.'/u';

    private const OFF_TOPIC = '/(?:^|\s)(?:promo|promos|discount|discounts|rate|rates|term|terms|payment|payments|downpayment|dp|'
        .'schedule|sched|slot|slots|viewing|tripping|appointment|freebie|freebies|offer|offers|financing|loan|pag-ibig|pagibig|'
        .'note|notes|seminar|event|webinar|voucher)(?=\s|$)/u';

    private const PERSON_BEFORE = '/(?:^|\s)(?:i|we|i\'m|im|i am|we\'re|we are|you\'re|you are|ako|kami)$/u';

    private const PERSON_AFTER = '/^(?:(?:na|po)\s+)*(?:ako|kami|ko|me|si|sir|maam|ma\'am|mam)(?=\s|$)/u';

    private const PROPERTY_WORDS = '/(?:^|\s)(?:unit|property|house|lot|condo|bahay|balay|yuta|lote|listing|ito|iyan|yan|yun|kini|kana|this|it)(?=\s|$)/u';

    /** @var array<string, array<int, array{phrase: string, regex: string}>>|null */
    private static ?array $compiled = null;

    /**
     * The verdict for one message: the strongest claim it makes, or null.
     *
     * @param  bool  $forRent  the listing is For Rent (rent phrases count in full)
     * @return array{tier: string, phrase: string, snippet: string}|null
     */
    public function match(?string $body, bool $forRent = false): ?array
    {
        $best = null;
        foreach ($this->candidates($body, $forRent) as $candidate) {
            if ($candidate['accepted'] && ($best === null || self::rank($candidate['tier']) < self::rank($best['tier']))) {
                $best = $candidate;
            }
        }

        return $best === null ? null : [
            'tier' => $best['tier'],
            'phrase' => $best['phrase'],
            'snippet' => $this->snippet((string) $body, $best['phrase']),
        ];
    }

    /**
     * Every candidate the lists found and why each was kept, dropped or
     * downgraded — for tests and for tuning the lists against real messages.
     *
     * @return array<int, array{tier: string, phrase: string, sentence: string, accepted: bool, reason: string|null, downgraded: string|null}>
     */
    public function explain(?string $body, bool $forRent = false): array
    {
        return $this->candidates($body, $forRent);
    }

    private function candidates(?string $body, bool $forRent): array
    {
        $text = $this->normalize($body);
        if ($text === '' || ! preg_match(self::PREFILTER, $text)) {
            return [];
        }

        $found = [];
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $claimed = []; // byte ranges of this sentence a longer phrase already took
            foreach (self::compiled() as $tier => $phrases) {
                foreach ($phrases as ['phrase' => $phrase, 'regex' => $regex]) {
                    if (! preg_match_all($regex, $sentence, $matches, PREG_OFFSET_CAPTURE)) {
                        continue;
                    }
                    foreach ($matches[0] as [$hit, $start]) {
                        $end = $start + strlen($hit);
                        foreach ($claimed as [$s, $e]) {
                            if ($start < $e && $end > $s) {
                                continue 2;
                            }
                        }
                        $claimed[] = [$start, $end];

                        $before = preg_split(self::CLAUSE_BREAK, substr($sentence, 0, $start));
                        $before = (string) end($before);
                        $after = (string) preg_split(self::CLAUSE_BREAK, substr($sentence, $end))[0];

                        $reason = $this->rejection($tier, $phrase, $sentence, $before, $after, substr($sentence, $end));
                        $downgraded = null;
                        if ($reason === null && $tier !== 'weak') {
                            if ($this->hedged(self::words($before), self::words($after))) {
                                $downgraded = 'hedged';
                            } elseif (! $forRent && in_array($phrase, self::RENTAL, true)) {
                                $downgraded = 'rent_phrase_on_sale';
                            }
                        }

                        $found[] = [
                            'tier' => $downgraded !== null ? 'weak' : $tier,
                            'phrase' => $phrase,
                            'sentence' => $sentence,
                            'accepted' => $reason === null,
                            'reason' => $reason,
                            'downgraded' => $downgraded,
                        ];
                    }
                }
            }
        }

        return $found;
    }

    /** Why a candidate doesn't count — null when it does. */
    private function rejection(string $tier, string $phrase, string $sentence, string $before, string $after, string $rest): ?string
    {
        $wordsBefore = self::words($before);
        $wordsAfter = self::words($after);

        if (str_contains($before.$after, '?') || in_array('ba', [...$wordsBefore, ...$wordsAfter], true)) {
            return 'question';
        }

        $window = array_slice($wordsBefore, -4);
        foreach ($window as $i => $word) {
            if (in_array($word, self::NEGATORS, true)) {
                // "wala" alone is "none": only "wala pa" / "wa pa" is "not yet".
                if (! in_array($word, ['wala', 'wla', 'wa'], true) || ($window[$i + 1] ?? null) === 'pa') {
                    return 'negation';
                }
            }
        }
        if (in_array('yet', array_slice($wordsAfter, 0, 2), true)) {
            return 'negation';
        }

        if (array_intersect(array_slice($wordsBefore, -4), self::CONDITIONALS) !== []
            || str_contains(' '.implode(' ', $wordsBefore).' ', ' in case ')) {
            return 'conditional';
        }

        if (preg_match(self::OTHER_UNIT_BEFORE, implode(' ', array_slice($wordsBefore, -6)))
            || preg_match(self::OTHER_UNIT_AFTER, implode(' ', array_slice($wordsAfter, 0, 5)))) {
            return 'other_unit';
        }

        if (preg_match(self::PRICE_AFTER, $rest)) {
            return 'price';
        }

        if (preg_match(self::OFF_TOPIC, implode(' ', array_slice($wordsBefore, -4)))
            || preg_match(self::OFF_TOPIC, implode(' ', array_slice($wordsAfter, 0, 3)))) {
            return 'off_topic';
        }

        if (in_array($phrase, self::GENERIC, true)
            && (preg_match(self::PERSON_BEFORE, implode(' ', array_slice($wordsBefore, -2)))
                || preg_match(self::PERSON_AFTER, implode(' ', array_slice($wordsAfter, 0, 3))))) {
            return 'person';
        }

        if ($tier === 'weak' && (count(self::words($sentence)) > 8 || ! preg_match(self::PROPERTY_WORDS, implode(' ', self::words($sentence))))) {
            return 'weak_without_context';
        }

        return null;
    }

    /** "baka sold na", "sold na yata", "I think it's already sold". */
    private function hedged(array $before, array $after): bool
    {
        return array_intersect(array_slice($before, -4), self::HEDGES) !== []
            || array_intersect(array_slice($after, 0, 2), self::HEDGES) !== [];
    }

    private function normalize(?string $body): string
    {
        if ($body === null || trim($body) === '') {
            return '';
        }

        $text = preg_replace('~https?://\S+|www\.\S+|\S+@\S+\.\S+~iu', ' ', $body) ?? '';
        $text = preg_replace('~\S+\.(?:jpe?g|png|gif|webp|heic|pdf|docx?|xlsx?|mp4|mov)\b~iu', ' ', $text) ?? '';
        $text = mb_strtolower($text);
        $text = str_replace(["\u{2019}", "\u{2018}", '`', '…'], ["'", "'", "'", '...'], $text);
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? '';
        $text = preg_replace("/\b(is|was|has|have|does|did)n't\b/u", '$1 not', $text) ?? '';
        $text = preg_replace('/\b(?:alr|alrdy|alrd|alredy|allready|alrady)\b/u', 'already', $text) ?? '';
        // "naa na'y" is written both ways.
        $text = preg_replace("/\bna'y\b/u", 'nay', $text) ?? '';
        // Particles typed joined: "sold napo", "nabenta nadin", "soldna".
        $text = preg_replace('/\bna(po|ho|din|rin|lang|pud|sad)\b/u', 'na $1', $text) ?? '';
        $text = preg_replace('/\bsoldna\b/u', 'sold na', $text) ?? '';
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /** Each phrase as a boundary-anchored regex; tiers strongest first, phrases longest first. */
    private static function compiled(): array
    {
        if (self::$compiled !== null) {
            return self::$compiled;
        }

        $gap = '(?:[\s\-]+(?:'.self::PARTICLES.')(?![\p{L}\p{N}]))?[\s\-]+';
        $out = [];
        foreach (['strong' => self::STRONG, 'medium' => self::MEDIUM, 'weak' => self::WEAK] as $tier => $phrases) {
            usort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($phrases as $phrase) {
                $re = '';
                foreach (preg_split('/( |-)/', $phrase, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                    $re .= match ($part) {
                        ' ' => $gap,
                        '-' => '[\s\-]?',
                        default => preg_quote($part, '/'),
                    };
                }
                $guard = match (true) {
                    $phrase === 'halin na' => '(?![\s\-]+(?:sa|kay|diri|didto|gikan)(?![\p{L}\p{N}]))',
                    str_ends_with($phrase, 'taken') => '(?![\s\-]+(?:note|notes|care|into|down|over|up|off|photos?|pictures?|pics?)(?![\p{L}\p{N}]))',
                    default => '',
                };
                $out[$tier][] = [
                    'phrase' => $phrase,
                    'regex' => '/(?<![\p{L}\p{N}\-\'])'.$re.'(?![\p{L}\p{N}]|-[\p{L}\p{N}])'.$guard.'/u',
                ];
            }
        }

        return self::$compiled = $out;
    }

    /** @return array<int, string> */
    private static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $word = trim($word, ".,!?;:\"'()[]*~");
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    private static function rank(string $tier): int
    {
        return ['strong' => 0, 'medium' => 1, 'weak' => 2][$tier];
    }

    /** The whole message when short; otherwise a 280-character window around the phrase. */
    private function snippet(string $body, string $phrase): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if (mb_strlen($body) <= 280) {
            return $body;
        }

        $parts = preg_split('/[ \-]/', $phrase);
        usort($parts, fn ($a, $b) => strlen($b) <=> strlen($a));
        $pos = mb_stripos($body, $parts[0]);
        $start = $pos === false ? 0 : max(0, $pos - 100);
        $window = mb_substr($body, $start, 280);

        return ($start > 0 ? '…' : '').trim($window).($start + 280 < mb_strlen($body) ? '…' : '');
    }
}
