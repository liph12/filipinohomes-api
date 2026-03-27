<?php

namespace App\Services\Jobs;

use App\Models\Listing;
use Illuminate\Support\Facades\DB;

class ListingService
{
    public function __construct()
    {
        // to do
    }

    public function updateBatchListings()
    {
        $state = DB::table('scheduler_states')
            ->where('key', 'listing_update')
            ->first();

        $lastId = $state->last_id ?? 0;
        $listings = Listing::where('id', '>', $lastId)
        ->orderBy('id')
        ->limit(1000)
        ->pluck('id');

        if ($listings->isEmpty()) {
            DB::table('scheduler_states')->updateOrInsert(
                ['key' => 'listing_update'],
                [
                    'last_id' => 0,
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );

            return response()->json([
                'message' => 'Reset complete',
                'processed' => 0
            ]);
        }

        $maxId = $listings->max();

        Listing::whereIn('id', $listings)->update([
            'updated_at' => now()
        ]);

        DB::table('scheduler_states')->updateOrInsert(
            ['key' => 'listing_update'],
            [
                'last_id' => $maxId,
                'updated_at' => now(),
                'created_at' => now()
            ]
        );

        return [
            'message' => 'Batch updated',
            'processed' => $listings->count(),
            'last_id' => $maxId
        ];
    }
}