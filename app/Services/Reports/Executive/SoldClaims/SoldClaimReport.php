<?php

namespace App\Services\Reports\Executive\SoldClaims;

use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\ReportRange;
use App\Services\Reports\Executive\RowHydrator;
use Illuminate\Support\Carbon;

/**
 * "Said it's sold, still listed" — one row per listing an agent-side reply
 * called sold (tier strong) or reserved (medium) in the dates, and that is
 * still not marked sold / rented / leased.
 *
 * The rows are claims.sold_unmarked / claims.reserved_unmarked themselves
 * (MetricEngine::source()), so the list's total is the tile's number. Each row
 * shows the latest claim, the conversations it was said in (opened in a new
 * tab from the page) and the leads that still arrived after it. Read-only:
 * nothing here changes a listing.
 */
final class SoldClaimReport
{
    public const MAX_PER_PAGE = 200;

    private const CONVERSATIONS = 5;

    public function __construct(
        private readonly MetricEngine $engine,
        private readonly ReportQueries $q,
        private readonly RowHydrator $rows,
    ) {}

    public function page(ReportRange $range, Carbon $now, string $tier, array $filters, int $page = 1, int $perPage = 25): array
    {
        $metric = $tier === 'medium' ? 'claims.reserved_unmarked' : 'claims.sold_unmarked';
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);

        [$base] = $this->engine->source($metric, $range, $now, $filters);
        $total = (int) (clone $base)->distinct()->count('listings.id');

        $ids = (clone $base)
            ->selectRaw('listings.id as id, MAX(sc.said_at) as last_said')
            ->groupBy('listings.id')
            ->orderByDesc('last_said')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $claims = $ids === [] ? collect() : (clone $base)
            ->whereIn('listings.id', $ids)
            ->leftJoin('users as sc_speaker', 'sc_speaker.id', '=', 'sc.speaker_user_id')
            ->leftJoin('users as sc_client', 'sc_client.id', '=', 'sc.client_user_id')
            ->orderByDesc('sc.said_at')
            ->orderByDesc('sc.id')
            ->get([
                'sc.id', 'sc.listing_id', 'sc.chat_id', 'sc.tier', 'sc.phrase', 'sc.snippet', 'sc.said_at',
                'sc.speaker_user_id', 'sc.speaker_role', 'sc_speaker.name as speaker_name', 'sc_client.name as client_name',
            ])
            ->groupBy('listing_id');

        $after = $this->engine->group('claims.inquiries_after', 'listing', $range, $now, $filters);
        $details = $this->q->listingDetails($ids);

        $rows = [];
        foreach ($ids as $id) {
            $d = $details->get($id);
            $own = $claims->get($id) ?? collect();
            $latest = $own->first();
            if ($d === null || $latest === null) {
                continue;
            }
            $rows[] = [
                'listing' => $this->rows->listingCell($d->id, $d->name, $d->slug, $d->code, $d->featured_photo),
                'place' => $this->rows->place($d->city, $d->province),
                'status' => $d->status,
                'price' => $d->price !== null ? (float) $d->price : null,
                'agent' => $this->rows->agentCell($d->agent_id, $d->agent_first_name, $d->agent_last_name, $d->agent_user_name, $d->agent_avatar, $d->agent_user_avatar),
                'claim' => [
                    'said_at' => Carbon::parse((string) $latest->said_at, 'UTC')->setTimezone(ReportRange::TZ)->toIso8601String(),
                    'tier' => $latest->tier,
                    'phrase' => $latest->phrase,
                    'snippet' => $latest->snippet,
                    'speaker' => ['id' => (int) $latest->speaker_user_id, 'name' => $latest->speaker_name, 'role' => $latest->speaker_role],
                ],
                'claims' => $own->count(),
                'conversations' => $own->unique('chat_id')->take(self::CONVERSATIONS)->map(fn ($c) => [
                    'chat_id' => (int) $c->chat_id,
                    'type' => 'listing',
                    'listing_name' => $d->name,
                    'client_name' => $c->client_name,
                ])->values()->all(),
                'inquiries_after' => $after[(string) $id] ?? 0,
                'filters' => ['listing_id' => $id],
            ];
        }

        return [
            'tier' => $tier,
            'metric' => $metric,
            'range' => $range->toArray(),
            'filters' => (object) $filters,
            'summary' => [
                'strong' => $this->engine->value('claims.sold_unmarked', $range, $now, $filters),
                'medium' => $this->engine->value('claims.reserved_unmarked', $range, $now, $filters),
                'inquiries_after' => $this->engine->value('claims.inquiries_after', $range, $now, $filters),
            ],
            'rows' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }
}
