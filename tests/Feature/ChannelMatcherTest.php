<?php

namespace Tests\Feature;

use App\Support\ChannelMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * visits.channel attribution. Hosts match by domain, not by "contains these letters" — the old substring
 * match read chatgpT.COm, copiloT.COm, reddiT.COm and yandeX.COM as Twitter.
 */
class ChannelMatcherTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'chatgpt referrer' => [null, null, 'https://chatgpt.com/', 'chatgpt'],
            'chatgpt tag beats the host' => ['chatgpt.com', null, 'https://m.facebook.com/', 'chatgpt'],
            'copilot referrer' => [null, null, 'https://copilot.microsoft.com/', 'copilot'],
            'copilot tag' => ['copilot.com', null, null, 'copilot'],
            'qwant is not twitter' => [null, null, 'https://www.qwant.com/', 'referral'],
            'reddit is not twitter' => [null, null, 'https://www.reddit.com/r/x', 'referral'],
            'yandex is not twitter' => [null, null, 'https://yandex.com/', 'referral'],
            'pinterest is not twitter' => [null, null, 'https://www.pinterest.com/', 'referral'],
            't.co' => [null, null, 'https://t.co/abc', 'twitter'],
            'x.com' => [null, null, 'https://x.com/', 'twitter'],
            'twitter android app' => [null, null, 'android-app://com.twitter.android', 'twitter'],
            'facebook mobile' => [null, null, 'https://m.facebook.com/', 'facebook'],
            'messenger link shim' => [null, null, 'https://l.messenger.com/', 'facebook'],
            'instagram' => [null, null, 'https://l.instagram.com/', 'instagram'],
            'a host with "ig" in it' => [null, null, 'https://compfight.com/', 'referral'],
            'google country domain' => [null, null, 'https://www.google.com.ph/', 'google'],
            'gemini is not google' => [null, null, 'https://gemini.google.com/', 'gemini'],
            'youtube android app' => [null, null, 'android-app://com.google.android.youtube', 'youtube'],
            'bing' => [null, null, 'https://www.bing.com/', 'bing'],
            'fb tag' => ['fb', null, null, 'facebook'],
            'ig tag' => ['ig', null, null, 'instagram'],
            'facebook_page tag' => ['facebook_page', null, null, 'facebook'],
            'perplexity tag' => ['perplexity', null, null, 'perplexity'],
            'unknown tag' => ['threads', null, null, 'referral'],
            'fbclid on a bare link' => [null, 'fbclid', 'https://www.google.com/', 'facebook'],
            'nothing at all' => [null, null, null, 'direct'],
        ];
    }

    #[DataProvider('cases')]
    public function test_channel(?string $utm, ?string $clickId, ?string $referrer, string $want): void
    {
        $this->assertSame($want, ChannelMatcher::resolve($utm, $clickId, $referrer));
    }
}
