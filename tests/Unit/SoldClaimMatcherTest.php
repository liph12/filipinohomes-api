<?php

namespace Tests\Unit;

use App\Services\Reports\Executive\SoldClaims\SoldClaimMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sold-claim matcher against agent replies in English, Tagalog/Taglish,
 * Cebuano and Hiligaynon. Every "not a claim" case names the guard that must
 * stop it, so a guard that quietly stops working fails here by name.
 */
class SoldClaimMatcherTest extends TestCase
{
    /** [reply, tier, phrase, listing is for rent] */
    public static function claims(): array
    {
        return [
            // English
            'has already been sold' => ['Hello! The unit has already been sold. Thank you for your interest.', 'strong', 'already been sold'],
            'already sold po' => ['Good day maam, already sold po', 'strong', 'already sold'],
            '"alr" shorthand' => ['alr sold po sir', 'strong', 'already sold'],
            'was sold' => ['It was sold last month po', 'strong', 'was sold'],
            'sold out' => ['Sold out na po ito', 'strong', 'sold-out'],
            'no longer available' => ['This unit is no longer available.', 'strong', 'no longer available'],
            "isn't available anymore" => ["Sorry, it isn't available anymore", 'strong', 'not available anymore'],
            'off the market' => ['Off the market na po', 'strong', 'off the market'],
            // Tagalog / Taglish
            'sold na po' => ['Sorry po sir, sold na po yung unit.', 'strong', 'sold na'],
            'in capitals' => ['SOLD NA PO', 'strong', 'sold na'],
            '"soldna" typed joined' => ['soldna po', 'strong', 'sold na'],
            'nasold' => ['nasold na po last week', 'strong', 'na-sold'],
            'naibenta' => ['Naibenta na po ang property', 'strong', 'nai-benta'],
            'nabenta' => ['nabenta na po siya', 'strong', 'na-benta'],
            'a particle inside the phrase' => ['Hindi na po available ang unit na yan', 'strong', 'hindi na available'],
            '"pong" inside the phrase' => ['Wala na pong available na unit', 'strong', 'wala na available'],
            'taken na' => ['Taken na po yung unit', 'strong', 'taken na'],
            'may nakabili na' => ['may nakabili na po', 'strong', 'may nakabili na'],
            'a "no" in its own clause' => ['Hindi po, sold na po', 'strong', 'sold na'],
            'emoji after it' => ['Sold na po 😊🙏', 'strong', 'sold na'],
            // Cebuano / Bisaya
            'nahalin na' => ['Nahalin na ni sir', 'strong', 'na-halin'],
            'halin na' => ['Sorry sir, halin na ang unit', 'strong', 'halin na'],
            'nabaligya na' => ['Nabaligya na to sir', 'strong', 'na-baligya'],
            'naa nay nakapalit' => ['Naa nay nakapalit ani sir', 'strong', 'naa nay nakapalit'],
            "na'y with an apostrophe" => ["Naa na'y nipalit ani maam", 'strong', 'naa nay nipalit'],
            'dili na available' => ['Dili na available ang unit sir', 'strong', 'dili na available'],
            '"jud" inside the phrase' => ['Dili na jud available ang unit', 'strong', 'dili na available'],
            // Hiligaynon
            'indi na available' => ['Indi na available ang unit', 'strong', 'indi na available'],
            'nabakal na' => ['Nabakal na ang balay', 'strong', 'na-bakal'],
            // Rent phrases on a For Rent listing
            'rented na, for rent' => ['Rented na po yung unit', 'strong', 'rented na', true],
            'giabangan na, for rent' => ['Giabangan na ang unit sir', 'strong', 'giabangan na', true],
            // Medium — reserved / on hold
            'reserved na' => ['Reserved na po yung unit', 'medium', 'reserved na'],
            'may buyer na' => ['May buyer na po, pero I have other options', 'medium', 'may buyer na'],
            'naa nay nag-reserve' => ['Naa nay nag-reserve ani sir', 'medium', 'naa nay nag-reserve'],
            'reserved by another client' => ['The unit is already reserved by another client', 'medium', 'already reserved'],
            'the strongest claim wins' => ['Reserved na po kanina, and now sold na po.', 'strong', 'sold na'],
            // Weak — stored for tuning, never counted
            'a rent phrase on a sale listing' => ['This unit is already rented, good for investment', 'weak', 'already rented'],
            'hedged before' => ['Baka sold na po yan', 'weak', 'sold na'],
            'hedged after' => ['Sold na yata yung unit', 'weak', 'sold na'],
            'bare "sold", short, about the unit' => ['This one sold', 'weak', 'sold'],
            '"wala na" about the unit' => ['Wala na po yung unit', 'weak', 'wala na'],
        ];
    }

    #[DataProvider('claims')]
    public function test_it_recognises_a_claim(string $reply, string $tier, string $phrase, bool $forRent = false): void
    {
        $match = (new SoldClaimMatcher)->match($reply, $forRent);

        $this->assertNotNull($match, "Expected a claim in: {$reply}\n".json_encode((new SoldClaimMatcher)->explain($reply, $forRent), JSON_PRETTY_PRINT));
        $this->assertSame([$tier, $phrase], [$match['tier'], $match['phrase']]);
    }

    /** [reply, the guard that must stop it — null when no phrase may match at all] */
    public static function nonClaims(): array
    {
        return [
            'a question' => ['Sold na po ba?', 'question'],
            'an English question' => ['Is it already sold?', 'question'],
            '"ba" without a question mark' => ['Sold na ba ang unit', 'question'],
            'hindi pa' => ['Hindi pa po sold', 'negation'],
            'wala pa' => ['Wala pa po nahalin', 'negation'],
            'dili pa' => ['Dili pa nahalin sir, available pa', 'negation'],
            'not sold yet' => ['The unit is not sold yet', 'negation'],
            "hasn't been sold" => ["It hasn't been sold", 'negation'],
            'available, not sold' => ['Available pa po, not sold', 'negation'],
            'kung (if)' => ['Kung sold na, I will inform you', 'conditional'],
            'if' => ["If it's already sold I'll let you know", 'conditional'],
            'another phase' => ['Phase 1 is already sold out, but Phase 2 units are available', 'other_unit'],
            'room-type inventory' => ['The 2BR units are sold out, we only have studio left', 'other_unit'],
            'the other one' => ['The unit you asked about is available, the other one is sold na', 'other_unit'],
            'yung isa (the other one)' => ['Sold na po yung isa', 'other_unit'],
            'a count of units' => ['I already sold 3 units in this project', 'other_unit'],
            'a sale price' => ['It was sold at 4.5M last year', 'price'],
            'a price with commas' => ['The unit next door sold for 5,200,000', 'price'],
            'a promo' => ['The promo is no longer available', 'off_topic'],
            'a promo, Cebuano' => ['Dili na available ang promo sir', 'off_topic'],
            'a viewing slot' => ['Already reserved your viewing slot for Saturday', 'off_topic'],
            'the agent themself' => ["I'm no longer available on Saturday, can we do Sunday?", 'person'],
            'si sir' => ['Hindi na available si sir bukas', 'person'],
            'a reservation the agent made' => ['I already reserved it for you', 'person'],
            '"wala na" with nothing about the unit' => ['Wala na po', 'weak_without_context'],
            'taken note' => ["I've already taken note of your request", null],
            'soldier' => ['The soldier bought a house nearby', null],
            'unsold' => ['Unsold units are still available', null],
            'resold' => ['This was resold by the bank', null],
            'barangay Naghalin' => ['The lot is in Barangay Naghalin, Kidapawan', null],
            'Guinhalinan' => ['Located at Guinhalinan, Batangas', null],
            'mahalin (to love)' => ['Mahalin ko ikaw', null],
            'the surname Macabenta' => ['Please contact Atty. Macabenta', null],
            'gibaligya (FOR sale)' => ['Gibaligya pa ni sir, available pa', null],
            'ibebenta (will sell)' => ['Ibebenta na po ng owner', null],
            'pang-benta (for selling)' => ['Pang-benta na po ito', null],
            'halin na sa (Hiligaynon "from")' => ['Halin na sa Iloilo ang papeles', null],
            'still available' => ['The unit is still available po', null],
            'a file name' => ['sold_unit_photo.jpg', null],
            'a link' => ['See https://example.com/already-sold-units', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('nonClaims')]
    public function test_it_refuses_what_is_not_a_claim(string $reply, ?string $guard): void
    {
        $matcher = new SoldClaimMatcher;
        $explained = $matcher->explain($reply);

        $this->assertNull($matcher->match($reply), "Wrongly matched: {$reply}\n".json_encode($explained, JSON_PRETTY_PRINT));
        if ($guard === null) {
            $this->assertSame([], $explained, 'No phrase should even be a candidate.');
        } else {
            $this->assertContains($guard, array_column($explained, 'reason'), json_encode($explained, JSON_PRETTY_PRINT));
        }
    }

    public function test_a_long_reply_keeps_a_window_around_the_phrase(): void
    {
        $reply = str_repeat('Good morning po, thank you for reaching out to us today. ', 6)
            .'Unfortunately, sold na po yung unit last week. '
            .str_repeat('We have many other listings in the area that might interest you. ', 6);

        $match = (new SoldClaimMatcher)->match($reply);

        $this->assertSame('strong', $match['tier']);
        $this->assertLessThanOrEqual(282, mb_strlen($match['snippet']));
        $this->assertStringContainsString('sold na po yung unit', $match['snippet']);
        $this->assertStringStartsWith('…', $match['snippet']);
    }

    public function test_the_phrase_lists_are_clean(): void
    {
        $all = [...SoldClaimMatcher::STRONG, ...SoldClaimMatcher::MEDIUM, ...SoldClaimMatcher::WEAK];

        $this->assertSame(array_unique($all), $all, 'A phrase is listed twice.');
        foreach ($all as $phrase) {
            $this->assertSame(mb_strtolower($phrase), $phrase, "Phrases are matched lowercased: {$phrase}");
            $this->assertStringNotContainsString('gibaligya', $phrase, '"Gibaligya" means FOR sale.');
        }
        $this->assertSame([], array_diff(SoldClaimMatcher::RENTAL, SoldClaimMatcher::STRONG), 'Every rent phrase is a strong phrase.');
    }
}
