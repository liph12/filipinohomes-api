<?php

namespace App\Mcp\Tools\Kinds;

use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpRole;
use App\Mcp\Tools\FhTool;
use App\Services\FhOne\BreakdownService;
use App\Services\Reports\Executive\MetricRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * "What can I ask?": everything this person can ask the connector, by topic, with what each topic
 * covers and example questions, for Claude to show in full. The numbers each topic covers come from
 * the catalog through the person's own policy, so the guide never offers something their tools would
 * refuse and stays right as numbers are added; only the topics' titles and example questions are
 * written here. An admin's guide also has the tools that are not catalog numbers (traffic, AI
 * visibility, the client journey).
 */
abstract class GuideTool extends FhTool
{
    /**
     * The catalog's numbers by topic, in the order shown: [title, id prefixes, examples per audience].
     * Example questions are written for an admin ("company"), a team leader ("team") and an agent ("own").
     */
    private const TOPICS = [
        'inquiries' => ['Inquiries', ['inquiries.'], [
            'company' => ['Unanswered inquiries by team this month', 'What is our median first-reply time this month vs last month?', 'Which inquiries are still waiting for approval?'],
            'team' => ['Unanswered inquiries by member this month', 'What is my team\'s median first-reply time?', 'Which of my team\'s inquiries have no reply after a day?'],
            'own' => ['How many inquiries did I get this month?', 'Which of my inquiries have no reply yet?', 'What is my median first-reply time?'],
        ]],
        'leads' => ['Leads & direct messages', ['leads.', 'direct.', 'briefs.', 'open_house.'], [
            'company' => ['All leads this month by source', 'Which agents have the most leads nobody answered?', 'How many leads went to agents who were not active on FH?'],
            'team' => ['My team\'s leads this month', 'Which members have leads nobody answered?', 'Direct messages to my team that are still waiting'],
            'own' => ['How many leads did I get this month?', 'Direct messages I have not answered yet', 'How many Buyer & Seller Link briefs did I get?'],
        ]],
        'listings' => ['Listings & prices', ['listings.'], [
            'company' => ['Listings added in Cebu by property type this month', 'Live listings by office region', 'Median price per sqm for sale in Davao'],
            'team' => ['Listings my team added this month, by member', 'My team\'s live listings by city', 'Median price per sqm of my team\'s listings for sale'],
            'own' => ['How many listings did I add this month?', 'My live listings by city', 'Median price per sqm of my listings for sale'],
        ]],
        'deals' => ['Deals', ['deals.'], [
            'company' => ['Deals by team last quarter', 'How many listings were marked sold this month vs last month?'],
            'team' => ['My team\'s deals this quarter, by member', 'Sold vs rented for my team this year'],
            'own' => ['My deals this year', 'How many of my listings were marked sold this month?'],
        ]],
        'claims' => ['Said "sold" but still listed', ['claims.'], [
            'company' => ['Listings agents said were sold but never marked sold', 'How many leads came in after an agent said "sold"?'],
            'team' => ['My team\'s listings said to be sold but still listed'],
            'own' => ['Which of my listings did I say were sold but never marked?'],
        ]],
        'audit' => ['Audit & Authority to Sell', ['audit.', 'ats.'], [
            'company' => ['Which listings have an ATS expiring this month?', 'Flagged listings by team', 'How many listings are still not audited?'],
            'team' => ['My team\'s ATS expiring in the next 30 days', 'Which of my team\'s listings are flagged?'],
            'own' => ['Which of my listings have an ATS expiring soon?', 'Are any of my listings flagged or waiting for re-review?'],
        ]],
        'clients' => ['Clients & users', ['usage.'], [
            'company' => ['New clients this month vs last month', 'How many clients were active in the last 30 days?', 'Unfinished sign-ups this week'],
            'team' => [],
            'own' => [],
        ]],
        'agents' => ['Agents', ['agents.'], [
            'company' => ['Which agents went quiet this month?', 'Agents who have not opened FH in 30 days, by team', 'How many agents posted a listing this week?'],
            'team' => ['Which of my members went quiet this month?', 'Members who have not opened FH in 30 days'],
            'own' => [],
        ]],
        'teams' => ['Teams', ['teams.'], [
            'company' => ['Teams with no activity in the last 30 days', 'Teams without a leader'],
            'team' => [],
            'own' => [],
        ]],
    ];

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
        $policy = $this->policy();
        $role = $policy->role();
        $audience = match ($role) {
            McpRole::Admin => 'company',
            McpRole::TeamLeader => 'team',
            McpRole::Agent => 'own',
        };
        $prefix = match ($role) {
            McpRole::Admin => 'fh',
            McpRole::TeamLeader => 'team',
            McpRole::Agent => 'my',
        };

        $sections = $role === McpRole::Admin ? $this->companyOnlySections() : [];
        if ($role === McpRole::TeamLeader) {
            $sections[] = [
                'title' => 'Your team',
                'tools' => ['team_roster'],
                'covers' => ['the teams you lead and who is in each', 'whether the team\'s numbers count each member'],
                'examples' => ['Who is in my team?', 'Which of my members are counted in the team\'s numbers?'],
            ];
        }

        foreach (self::TOPICS as [$title, $prefixes, $examples]) {
            $covers = [];
            foreach (MetricRegistry::keys() as $key) {
                if ($policy->allowsMetric($key) && $this->startsWithAny($key, $prefixes)) {
                    $covers[] = MetricRegistry::get($key)['ai'];
                }
            }
            if ($covers === []) {
                continue;
            }
            $sections[] = [
                'title' => $title,
                'tools' => ["{$prefix}_metric", "{$prefix}_metric_breakdown", "{$prefix}_metric_records"],
                'covers' => $covers,
                'examples' => $examples[$audience],
            ];
        }

        $splits = array_map(fn (string $dim): string => BreakdownService::DIMENSIONS[$dim]['label'] ?? $dim, $policy->dimensions());

        return [
            'for' => match ($role) {
                McpRole::Admin => 'an admin: the whole company',
                McpRole::TeamLeader => 'a team leader: the teams you lead and their members, and your own numbers as an agent (ask for "my" numbers)',
                McpRole::Agent => 'an agent: your own listings, leads and deals only',
            },
            'present_it' => 'Show the person every section below, each with a short line on what it covers and its example questions. Do not shorten it to a few bullets or leave sections out. Then mention the "for any number" options and what cannot be done, and ask what they want to start with.',
            'dates' => 'Any days they name, up to a year at a time; the last 30 days when they do not say.',
            'splits' => $splits,
            'sections' => array_values(array_filter($sections, fn (array $s): bool => $s['examples'] !== [] || $s['covers'] !== [])),
            'for_any_number' => [
                'compare it with the period just before ("vs last month")',
                'split it by '.implode(', ', array_map('strtolower', $splits)),
                'list the records behind it (which listings, inquiries or agents)',
            ],
            'cannot' => 'Change, create, delete or send anything; show anyone\'s email or phone number'.match ($role) {
                McpRole::Admin => '.',
                McpRole::TeamLeader => '; or show another team\'s numbers.',
                McpRole::Agent => '; or show other agents\' numbers.',
            },
        ];
    }

    /** The admin's tools that are not catalog numbers. @return list<array<string, mixed>> */
    private function companyOnlySections(): array
    {
        return [
            [
                'title' => 'Website traffic',
                'tools' => ['fh_traffic'],
                'covers' => ['visits and unique visitors per source (google, facebook, direct, chatgpt, referral...)', 'which source brought each visitor first', 'new and returning clients each source brought', 'the sites behind "referral"', 'visitors by country, region and city', 'a day-by-day trend'],
                'examples' => ['Traffic by source last week', 'Which source brought the most first-time visitors this month?', 'Daily visitors for the last 14 days'],
            ],
            [
                'title' => 'AI visibility',
                'tools' => ['fh_ai_visibility'],
                'covers' => ['how often ChatGPT, Perplexity and Claude opened our pages to answer someone', 'AI search crawls', 'people who clicked through from an AI assistant', 'the pages and listings AI looks at most', 'a day-by-day trend'],
                'examples' => ['How many people came from ChatGPT this month?', 'Which listings do AI assistants look at most?', 'AI views per day for the last 2 weeks'],
            ],
            [
                'title' => 'Client journey',
                'tools' => ['fh_clients'],
                'covers' => ['the journey: anonymous visitors, signed up, saved a listing, inquired, inquired again (30 days, 90 days, 12 months, all time)', 'how many clients inquired and how many never did', 'clients who inquired more than once, and inquiries per client', 'what a missed reply costs: whether clients whose first inquiry went unanswered inquired again or came back'],
                'examples' => ['What does a missed reply cost us?', 'Show the client journey for the last 90 days', 'How many clients inquired more than once?'],
            ],
        ];
    }

    /** @param  list<string>  $prefixes */
    private function startsWithAny(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($key, $p)) {
                return true;
            }
        }

        return false;
    }
}
