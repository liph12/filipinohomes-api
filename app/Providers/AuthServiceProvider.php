<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Models\Listing;
use App\Policies\ListingPolicy;
use App\Models\ListingInquiry;
use App\Policies\ListingInquiryPolicy;
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     */
    protected $policies = [
        Listing::class => ListingPolicy::class, 
        ListingInquiry::class => ListingInquiryPolicy::class,

    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}