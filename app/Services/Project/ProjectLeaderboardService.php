<?php

namespace App\Services\Project;

/**
 * Builds the by-name aggregates block — portfolio totals plus the
 * Top-by-listings / Top-by-transactions leaderboards. Operates on the
 * already-aggregated per-project rows produced by ProjectByNameService,
 * so it issues no queries of its own.
 */
class ProjectLeaderboardService
{
    /**
     * Compute the by-name aggregates block (insights strip + Top 50 lists).
     */
    public function build($allRows, int $total): array
    {
        $sum = [
            'total_listings' => 0,
            'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0,
            'sold' => 0, 'rented' => 0, 'leased' => 0,
        ];
        foreach ($allRows as $r) {
            $sum['total_listings'] += (int) $r->total_listings;
            $sum['for_sale']       += (int) $r->for_sale;
            $sum['for_rent']       += (int) $r->for_rent;
            $sum['foreclosure']    += (int) $r->foreclosure;
            $sum['sold']           += (int) $r->sold;
            $sum['rented']         += (int) $r->rented;
            $sum['leased']         += (int) $r->leased;
        }

        $byListings = $allRows->sortByDesc(fn ($r) => (int) $r->total_listings)->values();
        $byTransactions = $allRows
            ->sortByDesc(fn ($r) => ((int) $r->sold) + ((int) $r->rented) + ((int) $r->leased))
            ->values();

        $topProject = $byListings->first();

        $topByListings = $byListings->take(50)->map(fn ($r) => [
            'project_key'    => (string) $r->project_key,
            'project_name'   => (string) $r->project_name,
            'total_listings' => (int) $r->total_listings,
        ])->values();

        $topByTransactions = $byTransactions
            ->filter(fn ($r) => ((int) $r->sold) + ((int) $r->rented) + ((int) $r->leased) > 0)
            ->take(50)
            ->map(function ($r) {
                $sold   = (int) $r->sold;
                $rented = (int) $r->rented;
                $leased = (int) $r->leased;
                return [
                    'project_key'        => (string) $r->project_key,
                    'project_name'       => (string) $r->project_name,
                    'sold'               => $sold,
                    'rented'             => $rented,
                    'leased'             => $leased,
                    'total_transactions' => $sold + $rented + $leased,
                ];
            })->values();

        return [
            'total_projects'      => $total,
            'total_listings'      => $sum['total_listings'],
            'total_for_sale'      => $sum['for_sale'],
            'total_for_rent'      => $sum['for_rent'],
            'total_foreclosure'   => $sum['foreclosure'],
            'total_sold'          => $sum['sold'],
            'total_rented'        => $sum['rented'],
            'total_leased'        => $sum['leased'],
            'top_project'         => $topProject ? [
                'project_key'    => (string) $topProject->project_key,
                'project_name'   => (string) $topProject->project_name,
                'total_listings' => (int) $topProject->total_listings,
            ] : null,
            'top_by_listings'     => $topByListings,
            'top_by_transactions' => $topByTransactions,
        ];
    }
}
