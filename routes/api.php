<?php
use App\Http\Controllers\{
    UserController,
    AgentController,
    CategoryController,
    PropertyTypeController,
    FurnishingController,
    PropertyAttributesController,
    PropertyController,
    ListingController,
    FullListingController,
    PropertySubtypeController,
    ProjectController,
    AmenityController,
    ImageUploadController,
    BlogCategoryController,
    PostController,
    GenerateDescriptionController,
    OfficeController,
    ProvinceController,
    CityController,
    OpenAIController,
    ChatController,
    ConversationController,
    FavoriteController,
    MagazineController,
    MessageController,
    MaintenanceController,
    FileUploadController,
    PageBuilderController,
    ReactionController,
    SitemapController,
    BackgroundJobController,
    AdCampaignController,
    AdController,
    AdSectionController,
    AdPlacementController,
    PublicAdController,
    BlockedUserController,
    TeamController,
    TeamAgentController,
    FeatureTokenController,
    GuestTokenController,
};
use App\Http\Controllers\AdPreviewController;
use App\Http\Controllers\HomesPhNewsController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\RoleMiddleware;
Route::middleware('strip.tags')->group(function(){
    Route::middleware('throttle:auth')->group(function(){
        Route::post('/login', [UserController::class, 'login']);
        Route::post('/auth/google', [GoogleAuthController::class, 'authenticate']);
        Route::post('/auth/dev-login', [UserController::class, 'devLogin']);
        Route::post('/auth-send-otp', [UserController::class, 'authWithOtp']);
        Route::post('/auth-request-verify-otp', [UserController::class, 'authRequestVerifyOtp']);
    });
    
    Route::middleware('throttle:api')->group(function(){
        // Issues a short-lived HMAC guest token for public API access
        Route::post('/guest-token', [GuestTokenController::class, 'issue']);

        Route::post('/inquiry', [UserController::class, 'sendInquiry']);
        Route::get('/blogs', [PostController::class, 'index']);
        Route::get('/blog-categories', [BlogCategoryController::class, 'index']);
        Route::get('/blogs/{slug}', [BlogCategoryController::class, 'show']);
        Route::get('/posts/{slug}', [PostController::class, 'show']);
        Route::post('/posts/{slug}/view', [PostController::class, 'trackView']);
        Route::post('/posts/{slug}/impression', [PostController::class, 'trackImpression']);
        Route::get('/offices', [OfficeController::class, 'index']);
        Route::get('/projects/unassociated', [ProjectController::class, 'unassociatedProjects']);
        Route::get('/project/{slug}', [ProjectController::class, 'show'])->where('slug', '.*');
        Route::get('/project-list-with-listings', [ProjectController::class, 'projectsWithListings']);
        Route::post('/project/{slug}/view', [ProjectController::class, 'trackView']);

        // Guest-token-protected public routes
        Route::middleware('verify.guest.token')->group(function () {
            Route::get('/search-by-location', [ListingController::class, 'listingsByLocation']);
            Route::get('/group-by-location', [ListingController::class, 'listingsByLocationAll']);
            Route::get('/group-by-city', [ListingController::class, 'listingByCityAll']);
            Route::get('/listings/subtype-counts', [ListingController::class, 'subtypeCounts']);
            Route::get('/listings/featured', [ListingController::class, 'featured']);
            Route::get('/listings/{slug}', [ListingController::class, 'show']);
            Route::get('/listings', [ListingController::class, 'index']);
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::get('/property_types', [PropertyTypeController::class, 'index']);
            Route::get('/property_subtypes', [PropertySubtypeController::class, 'index']);
            Route::get('/furnishings', [FurnishingController::class, 'index']);
            Route::get('/amenities', [AmenityController::class, 'index']);
            Route::get('agents', [AgentController::class, 'index']);
            Route::get('agents/deleted', [AgentController::class, 'deletedAgents'])->middleware('auth:sanctum');
            Route::get('agents/{id}/statistics', [AgentController::class, 'statistics']);
            Route::get('agents/{id}/activity', [AgentController::class, 'activity']);
            Route::get('agents/{id}', [AgentController::class, 'show']);

            Route::get('/resolve-properties-keywords', [ListingController::class, 'resolveByKeywordsAndSlug']);
        });
        Route::post('/openai/stream-reply', [OpenAIController::class, 'streamChat']);
        Route::post('/openai/search-listings', [OpenAIController::class, 'searchListings']);
        Route::post('/openai/search-agents', [OpenAIController::class, 'searchAgents']);
        Route::post('/openai/stream-message', [OpenAIController::class, 'streamMessageRequest']);
        Route::get('/openai/daily-limit', [OpenAIController::class, 'getDailyLimit']);
        Route::get('/openai/daily-limit-create', [OpenAIController::class, 'getDailyLimitCreate']);
        Route::get('/openai/daily-limit-create-text', [OpenAIController::class, 'getDailyLimitCreateText']);
        Route::get('/openai/cached-messages', [OpenAIController::class, 'getCachedMessages']);
        Route::post('/openai/clear-cached-messages', [OpenAIController::class, 'clearCachedMessages']);
        Route::get('/provinces', [ProvinceController::class, 'index']);
        Route::get('/provinces/{province}/cities', [ProvinceController::class, 'cities']);
        Route::get('/cities/{city}/barangays', [CityController::class, 'barangays']);
        Route::get('/maintenance-status', [MaintenanceController::class, 'status']);
        Route::get('/page/agents/check-slug', [PageBuilderController::class, 'checkSlug']);
        Route::get('/page/agents/agent/{agentId}', [PageBuilderController::class, 'showByAgent']);
        Route::get('/page/agents/deleted', [PageBuilderController::class, 'deleted'])
            ->middleware(['auth:sanctum']);
        Route::get('/page/agents/{slug}', [PageBuilderController::class, 'show']);
        Route::get('/page/agents', [PageBuilderController::class, 'index']);
        // PageBuilder public tracking
        Route::post('/page/agents/{slug}/impression', [PageBuilderController::class, 'trackImpression']);
        Route::post('/page/agents/{slug}/click', [PageBuilderController::class, 'trackClick']);
        Route::get('/offices/{slug}', [OfficeController::class, 'show']);
        Route::get('/__dev__/__admins__', [AgentController::class, 'admins']);

        // Lightweight sitemap endpoints
        Route::get('sitemap/listings', [SitemapController::class, 'listings']);
        Route::get('sitemap/agents', [SitemapController::class, 'agents']);
        Route::get('sitemap/blogs', [SitemapController::class, 'blogs']);
        Route::get('sitemap/listing-images', [SitemapController::class, 'listingImages']);
        Route::get('sitemap/blog-images', [SitemapController::class, 'blogImages']);
        Route::get('sitemap/agent-images', [SitemapController::class, 'agentImages']);
        Route::get('sitemap/search-locations', [ListingController::class, 'sitemapSearchLocations']);
        Route::get('sitemap/location-counts', [SitemapController::class, 'locationCounts']);
        
        // Public HomesPhNews proxy (server-side X-Site-Key, avoids CORS for browsers)
        Route::get('news', [HomesPhNewsController::class, 'index']);
        Route::get('news/{identifier}', [HomesPhNewsController::class, 'show']);
        Route::post('news/{identifier}/impression', [HomesPhNewsController::class, 'trackImpression']);
        Route::post('news/{identifier}/click', [HomesPhNewsController::class, 'trackClick']);

        // Public magazine routes
        Route::get('magazines', [MagazineController::class, 'index']);
        Route::get('magazines/years', [MagazineController::class, 'years']);
        Route::get('magazines/{magazine}', [MagazineController::class, 'show']);
        Route::get('magazines/{magazine}/pdf', [MagazineController::class, 'streamPdf']);
        
        // Public ad routes
        Route::get('/ads/section/{key}', [PublicAdController::class, 'show']);
        Route::post('/ads/{id}/impression', [PublicAdController::class, 'trackImpression']);
        Route::post('/ads/{id}/click', [PublicAdController::class, 'trackClick']);
    
        Route::middleware('auth:sanctum')->group(function(){
            Route::get('/client-logins', [UserController::class, 'getClients']);
            Route::get('/authenticate', [UserController::class, 'authenticate']);
            Route::post('/user/session-ping', [UserController::class, 'sessionPing']);
            Route::get('/users/search', [UserController::class, 'searchUsers']);
            Route::get('/openai/parse-listing-query', [OpenAIController::class, 'parseListingQuery']);
            Route::post('/openai/classify-photos', [OpenAIController::class, 'classifyListingPhotos']);
            Route::post('/openai/analyze-title', [OpenAIController::class, 'analyzeListingTitle']);
            Route::post('/openai/suggest-titles', [OpenAIController::class, 'suggestListingTitles']);
            Route::apiResource('users', UserController::class);
            Route::get('/teams/monthly-listings', [TeamController::class, 'monthlyListings']);
            Route::apiResource('teams', TeamController::class);
            Route::apiResource('team-agents', TeamAgentController::class);
            Route::apiResource('property_attributes', PropertyAttributesController::class);
            Route::apiResource('properties', PropertyController::class);
            Route::apiResource('listings', ListingController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('full-listing', FullListingController::class)->only(['store', 'show', 'update', 'destroy'])->parameters(['full-listing' => 'listing']);
            Route::patch('/listings/{listing}/visibility', [ListingController::class, 'updateVisibility']);
            Route::patch('/listings/{listing}/status', [ListingController::class, 'updateStatus']);
            Route::patch('/listings/{listing}/featured', [ListingController::class, 'updateIsFeatured']);
            Route::patch('/listings/{listing}/verify', [ListingController::class, 'updateVerification']);

            // Feature tokens
            Route::post('/feature-tokens/issue', [FeatureTokenController::class, 'issue']);
            Route::get('/feature-tokens/agent/{agentId}', [FeatureTokenController::class, 'indexForAgent']);
            Route::delete('/feature-tokens/{id}', [FeatureTokenController::class, 'revoke']);
            Route::get('/feature-tokens/my', [FeatureTokenController::class, 'myTokens']);
            Route::post('/feature-tokens/{id}/apply', [FeatureTokenController::class, 'apply']);
            Route::get('/my-listings', [ListingController::class, 'myListings']);
            Route::get('/all-listings', [ListingController::class, 'allListings']);
            Route::get('/user/dashboard', [ListingController::class, 'dashboard']);
            Route::get('/user/status-by-date', [ListingController::class, 'dashboardStatusByDate']);
            Route::get('/user/profile', [UserController::class, 'profile']);
            Route::get('/user/settings', [UserController::class, 'userSettings']);
            Route::post('agents', [AgentController::class, 'store']);
            Route::get('/agent/profile', [AgentController::class, 'profile']);
            Route::patch('agents/{id}/status', [AgentController::class, 'updateStatus']);
            Route::delete('agents/{id}', [AgentController::class, 'destroy']);
            Route::post('agents/{id}/restore', [AgentController::class, 'restore']);
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/project-list', [ProjectController::class, 'projects']);
            Route::get('/projects/by-province', [ProjectController::class, 'byProvince']);
            Route::get('/projects/insights/by-name', [ProjectController::class, 'byName']);
            Route::get('/projects/insights/detail/{projectKey}', [ProjectController::class, 'insightsDetail'])
                ->where('projectKey', '(project|property):\d+');
            Route::get('/listings/insights/by-province', [ListingController::class, 'insightsByProvince']);
            Route::get('/listings/insights/by-status', [ListingController::class, 'insightsByStatus']);
            Route::get('/listings/insights/status/{status}', [ListingController::class, 'insightsListingsForStatus'])
                ->where('status', '[a-z_-]+');
            Route::get('/projects/deleted', [ProjectController::class, 'deletedProjects']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::post('/projects/{id}/link-unassociated', [ProjectController::class, 'linkUnassociatedProperty']);
            Route::post('/projects/{id}/link-deleted-properties', [ProjectController::class, 'linkDeletedProjectProperties']);
            Route::post('/projects/{id}/restore', [ProjectController::class, 'restore']);
            Route::patch('/projects/{id}', [ProjectController::class, 'update']);
            Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
            Route::post('/upload', [ImageUploadController::class, 'upload']);
            Route::post('/upload-pdf', [FileUploadController::class, 'uploadFile']);
            Route::post('/logout', [UserController::class, 'logout']);
            Route::post('/generate-description', [GenerateDescriptionController::class, 'generate']);
            Route::post('/agent/profile', [AgentController::class, 'store']);
            Route::post('/favorites/sync', [FavoriteController::class, 'sync']);
            Route::post('/favorites/{listingId}', [FavoriteController::class, 'toggle']);
            Route::get('/favorites', [FavoriteController::class, 'index']);
            Route::post('/admin/maintenance-toggle', [MaintenanceController::class, 'toggle']);
            Route::post('/page/agents', [PageBuilderController::class, 'store']);
            Route::patch('/page/agents/{id}', [PageBuilderController::class, 'update']);
            Route::delete('/page/agents/{id}', [PageBuilderController::class, 'destroy']);
            
            Route::post('/page/agents/{id}/restore', [PageBuilderController::class, 'restore']);

    
            // Magazine, Office & Ad management (admin + editor only)
            Route::middleware(RoleMiddleware::class . ':admin,editor')->group(function () {
                Route::apiResource('magazines', MagazineController::class)->except(['index', 'show']);
                Route::post('/offices', [OfficeController::class, 'store']);
                Route::patch('/offices/{office}', [OfficeController::class, 'update']);
                Route::delete('/offices/{office}', [OfficeController::class, 'destroy']);
                Route::post('ad-preview-token', [AdPreviewController::class, 'generateToken']);
                Route::apiResource('ad-campaigns', AdCampaignController::class);
                Route::apiResource('ads', AdController::class);
                Route::apiResource('ad-sections', AdSectionController::class);
                Route::get('ad-placements/leaderboard/{sectionId}', [AdPlacementController::class, 'leaderboard']);
                Route::post('ad-placements/bulk', [AdPlacementController::class, 'bulkStore']);
                Route::apiResource('ad-placements', AdPlacementController::class);
                Route::get('/ads/analytics/{group}', [PublicAdController::class, 'getAnalytics']);
            });
        });
    });
    
    Route::middleware('throttle:chat')->group(function(){
        Route::middleware('auth:sanctum')->group(function(){
            Route::apiResource('chats', ChatController::class)->only(['index', 'store', 'show', 'destroy']);
            Route::apiResource('conversations', ConversationController::class)->only(['index', 'show']);
            Route::post('conversations/{conversation}/mark-read', [ConversationController::class, 'markRead']);
            Route::post('conversations/{conversation}/accept', [ConversationController::class, 'accept']);
            Route::post('conversations/{conversation}/reject', [ConversationController::class, 'reject']);
            Route::post('conversations/{conversation}/close', [ConversationController::class, 'close']);
            Route::post('conversations/{conversation}/reopen', [ConversationController::class, 'reopen']);
            Route::apiResource('messages', MessageController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::post('messages/{message}/reactions', [ReactionController::class, 'toggle']);
            Route::get('blocked-users', [BlockedUserController::class, 'index']);
            Route::get('blocked-users/check', [BlockedUserController::class, 'check']);
            Route::post('blocked-users', [BlockedUserController::class, 'store']);
            Route::delete('blocked-users/{blockedUser}', [BlockedUserController::class, 'destroy']);
        });
    }); 
});
// Background Jobs
Route::post('/listings/update-batch', [BackgroundJobController::class, 'execute']);
