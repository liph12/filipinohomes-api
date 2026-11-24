<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Support\McpActor;
use App\Mcp\Tools\FhTool;
use App\Services\User\ClientInsightsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * How far clients go, from the admin Client Insights page: repeat inquirers and inquiries per client,
 * the client journey, whether clients inquired at all, and what a missed reply costs. It reads the
 * page's own cached summary (ClientInsightsService::cachedSummary()), so Claude and the page agree and
 * share one computation. Counts and shares only: nothing that names or identifies a client, and none of
 * the page's per-agent table. Admins only, like the page.
 */
#[Name('fh_clients')]
#[Title('Filipino Homes: client journey and repeat inquiries')]
#[Description('How far Filipino Homes clients go, from the admin Client Insights page: the client journey (anonymous visitors → signed up → saved a listing → inquired → inquired more than once) for the last 30 days, 90 days, 12 months and all time; whether signed-up clients inquired at all; how many inquired more than once and how many inquiries each client sent (1, 2–3, 4–9, 10–19, 20+); and what a missed reply costs (whether clients whose first inquiry got a reply, or not, inquired again or came back). Counts and percentages only. Read-only.')]
final class FhClients extends FhTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        $s = app(ClientInsightsService::class)->cachedSummary();
        $t = $s['totals'];
        $pct = fn (int|float $part, int|float $whole): ?float => $whole > 0 ? round(100 * $part / $whole, 1) : null;

        $journey = [];
        foreach (['30d' => 'last_30_days', '90d' => 'last_90_days', '12m' => 'last_12_months', 'all' => 'all_time'] as $key => $name) {
            $j = $s['journey_ranges'][$key] ?? null;
            if ($j === null) {
                continue;
            }
            $journey[$name] = [
                'anonymous_visitors' => $j['anonymous'],
                'signed_up' => $j['clients'],
                'signed_up_pct_of_all_visitors' => $pct($j['clients'], $j['anonymous'] + $j['clients']),
                'saved_listings' => $j['saved'],
                'saved_pct_of_signed_up' => $pct($j['saved'], $j['clients']),
                'inquired' => $j['inquired'],
                'inquired_pct_of_signed_up' => $pct($j['inquired'], $j['clients']),
                'inquired_more_than_once' => $j['repeat'],
                'repeat_pct_of_inquired' => $pct($j['repeat'], $j['inquired']),
            ];
        }

        $missed = [];
        foreach (['replied' => 'got_a_reply', 'no_reply' => 'no_reply'] as $key => $name) {
            $m = $s['missed_reply'][$key];
            $missed[$name] = [
                'clients' => $m['clients'],
                'inquired_again' => $m['inquired_again'],
                'inquired_again_pct' => $pct($m['inquired_again'], $m['clients']),
                'came_back_to_the_site' => $m['came_back'],
                'came_back_pct' => $pct($m['came_back'], $m['clients']),
            ];
        }

        return [
            'as_of' => 'now; all clients ever, unless a journey period says otherwise',
            'clients' => $t['clients'],
            'did_they_inquire' => [
                'clients' => $t['clients'],
                'inquired' => $t['inquired'],
                'inquired_pct' => $pct($t['inquired'], $t['clients']),
                'never_inquired' => $t['clients'] - $t['inquired'],
                'never_inquired_pct' => $pct($t['clients'] - $t['inquired'], $t['clients']),
            ],
            'inquired_more_than_once' => [
                'clients' => $t['repeat'],
                'pct_of_clients_who_inquired' => $pct($t['repeat'], $t['inquired']),
            ],
            'inquiries_per_client' => $s['inquiry_buckets'],
            'client_journey' => $journey,
            'missed_reply_cost' => $missed,
            'how_counted' => 'Clients are accounts with the client role. An inquiry is a conversation a client started, on a listing or directly with an agent. inquiries_per_client: clients who inquired, by how many inquiries each sent. client_journey follows the people NEW in each period: anonymous_visitors = devices that first visited then and never made an account; signed_up = clients who signed up then; saved, inquired and inquired_more_than_once are how many of those clients did it at any time since. missed_reply_cost: clients by whether their FIRST inquiry (one that reached an agent) got a reply in the FH chat; inquired_again = sent another inquiry; came_back_to_the_site = were online at least a day after that first inquiry. Phone or WhatsApp replies cannot be seen. The same numbers as the admin Client Insights page (refreshed every 10 minutes).',
        ];
    }
}
