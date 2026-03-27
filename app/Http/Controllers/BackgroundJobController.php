<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Jobs\ListingService;

class BackgroundJobController extends Controller
{
    private $listingService;

    public function __construct(ListingService $l)
    {
        $this->listingService = $l;
    }

    public function execute()
    {
        $batch = $this->listingService->updateBatchListings();

        return response()->json($batch);
    }
}
