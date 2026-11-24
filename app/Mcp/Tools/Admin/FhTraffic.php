<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Support\Asks;
use App\Mcp\Support\McpActor;
use App\Mcp\Tools\FhTool;
use App\Services\Audience\AudienceGeographyService;
use App\Services\Audience\TrafficSourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Website traffic by source: where the website's visitors came from (Google,
 * Facebook, direct, ChatGPT...), with the referral sites inside "referral" and,
 * when asked, where they are. It is the admin Audience Insights page's own
 * numbers (TrafficSourceService, AudienceGeographyService) over the same
 * `visits` rows, so what Claude says and what the page shows agree. Admins
 * only, like that page: the numbers are the company's audience, not an
 * agent's or a team's.
 */
#[Name('fh_traffic')]
#[Title('Filipino Homes: website traffic by source')]
#[Description('Website visitors and where they came from (traffic source / acquisition channel: google, facebook, instagram, tiktok, youtube, direct, referral, chatgpt, gemini...) for some days (the last 30 by default): people and visits per source, new and returning clients each source brought, the sites behind "referral", and optionally visitors by country, region and city. The same numbers as the admin Audience Insights page. Agents\' and admins\' own browsing is not counted. Read-only.')]
final class FhTraffic extends FhTool
{
    /** How many referral sites to give; the page shows up to 50, Claude rarely needs more than this. */
    private const REFERRERS = 20;

    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...Asks::schema($schema, []),
            'geography' => $schema->boolean()->description('Also give the top 10 countries, regions and cities visitors and new clients are from.'),
            'country' => $schema->string()->max(32)->description('With geography: narrow the regions and cities to one country, as its two-letter code (e.g. "PH").'),
        ];
    }

    protected function rules(): array
    {
        return [
            ...Asks::rules([]),
            'geography' => ['nullable', 'boolean'],
            'country' => ['nullable', 'string', 'max:32'],
        ];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        $range = Asks::range($input);
        $start = $range->start;
        $end = $range->end;

        // Long ranges take seconds, as on the page; a range that has ended cannot change, so it keeps longer.
        $ttl = $end < now()->toDateString() ? now()->addHours(6) : now()->addMinutes(10);
        $traffic = Cache::remember("mcp:traffic:v1:{$start}:{$end}", $ttl, fn (): array => app(TrafficSourceService::class)->range($start, $end)->channels());

        $answer = [
            'days' => ['from' => $start, 'to' => $end],
            'unique_visitors' => $traffic['unique_visitors'],
            'anonymous_visitors' => $traffic['anonymous_visitors'],
            'visitors_from_more_than_one_source' => $traffic['multi_source_devices'],
            'sources' => array_map(fn (array $row): array => [
                'source' => $row['channel'],
                'people' => $row['value'],
                'visits' => $row['visits'],
                'first_source_of' => $row['first'],
                'new_clients' => $row['new'],
                'returning_clients' => $row['returning'],
            ], $traffic['channels']),
            'referral_sites' => array_slice($traffic['referrers'], 0, self::REFERRERS),
            'how_counted' => 'people = distinct devices that arrived from that source (a device that used two sources counts in both, so the people column can add up to more than unique_visitors); visits = device-days (one device from Google on 5 days is 5 visits); first_source_of = devices whose first visit in these days came from it (adds up to unique_visitors); new_clients = client sign-ups in these days, credited to the source of the device they signed up on; returning_clients = clients who signed in on a later day than they signed up, credited the same way ("unknown" when the device cannot be tied to a visit). referral_sites splits the "referral" source by site, app or utm tag. Agents\', admins\' and staff browsing is left out. Days are counted as the Audience Insights page counts them.',
        ];

        if ((bool) ($input['geography'] ?? false)) {
            $country = isset($input['country']) && $input['country'] !== '' ? strtoupper((string) $input['country']) : null;
            $answer['geography'] = app(AudienceGeographyService::class)->range($start, $end)->breakdown($country) + [
                'how_counted' => 'Top 10 each, by unique person: anonymous visitor devices by where they visited from, plus clients who signed up in these days by their saved location. Countries are always worldwide; regions and cities follow "country" when given.',
            ];
        }

        return $answer;
    }
}
