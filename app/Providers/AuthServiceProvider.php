<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Models\Listing;
use App\Policies\ListingPolicy;
use App\Models\ListingInquiry;
use App\Policies\ListingInquiryPolicy;
use App\Models\Chat;
use App\Policies\ChatPolicy;
use App\Models\Conversation;
use App\Policies\ConversationPolicy;
use App\Models\Message;
use App\Policies\MessagePolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     */
    protected $policies = [
        Listing::class => ListingPolicy::class,
        ListingInquiry::class => ListingInquiryPolicy::class,
        Chat::class => ChatPolicy::class,
        Conversation::class => ConversationPolicy::class,
        Message::class => MessagePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}