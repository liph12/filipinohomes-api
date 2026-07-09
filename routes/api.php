<?php
use App\Http\Controllers\{
    AppConfigController,
    AppVersionController,
    UserController,
    AgentController,
    CategoryController,
    PropertyTypeController,
    FurnishingController,
    PropertyAttributesController,
    PropertyController,
    ListingController,
    AudienceInsightsController,
    VisitController,
    FullListingController,
    PropertySubtypeController,
    ProjectController,
    AmenityController,
    ImageUploadController,
    GifUploadController,
    RemovedPhotoUploadController,
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
    BuyerFormController,
    ReactionController,
    SitemapController,
    BackgroundJobController,
    AdCampaignController,
    AdController,
    AdSectionController,
    AdPlacementController,
    PublicAdController,
    BlockedUserController,
    AgentReviewController,
    TeamController,
    TeamAgentController,
    FeatureTokenController,
    ImpersonationController,
    GuestTokenController,
    InquiryController,
    InquiryAnalyticsController,
    ActivityLogController,
    MobileStatisticsController,
    DeviceTokenController,
    NotificationController,
    AnnouncementController,
    SessionController,
    EmailChangeController,
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
        // Separate token for the external partner /fh-agent endpoint (own secret).
        Route::post('/fh-agent/token', [GuestTokenController::class, 'issueFhAgent']);

        // External partner agent lookup by EMAIL — gated by its OWN token
        // (X-FH-Agent-Token), independent of the site-wide guest token.
        //   POST /api/fh-agent/token            → { token }
        //   GET  /api/fh-agent/{email}          (header: X-FH-Agent-Token)
        Route::middleware('verify.fh.agent.token')->group(function () {
            Route::get('/fh-agent/{email}', [AgentController::class, 'showByEmail'])->where('email', '.*');
        });

        Route::post('/inquiry', [UserController::class, 'sendInquiry']);
        Route::post('/contact-us', [UserController::class, 'sendContactUs']);
        // Public acquisition ping — visitor source tracking (once per session).
        Route::post('/track/visit', [VisitController::class, 'store']);
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
            Route::get('/listings/featured', [ListingController::class, 'featured']);
            // Listings needing audit, role-scoped in the controller (admin = all,
            // team leader = their team's listings). Authed via auth:sanctum, but
            // registered here — before `/listings/{slug}` — so the literal
            // "audit-queue" segment is never bound as a {slug} and 404'd by show().
            Route::get('/listings/audit-queue', [ListingController::class, 'auditFeed'])->middleware(['auth:sanctum', 'agent.active']);
            Route::get('/listings/{slug}', [ListingController::class, 'show']);
            Route::get('/listings', [ListingController::class, 'index']);
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::get('/property_types', [PropertyTypeController::class, 'index']);
            Route::get('/property_subtypes', [PropertySubtypeController::class, 'index']);
            Route::get('/furnishings', [FurnishingController::class, 'index']);
            Route::get('/amenities', [AmenityController::class, 'index']);
            Route::get('agents', [AgentController::class, 'index']);
            // Must come BEFORE `agents/{id}` so it doesn't bind
            // "online-ids" as the {id} param.
            Route::get('agents/online-ids', [AgentController::class, 'onlineAgentIds']);
            Route::get('agents/deleted', [AgentController::class, 'deletedAgents'])->middleware(['auth:sanctum', 'agent.active']);
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
        // Mobile bootstrap config (maintenance + forced-update) and the public
        // app-downloads listing. Both must be reachable pre-login.
        Route::get('/app-config', [AppConfigController::class, 'show']);
        Route::get('/app-versions', [AppVersionController::class, 'index']);
        Route::get('/page/agents/check-slug', [PageBuilderController::class, 'checkSlug']);
        Route::get('/page/agents/agent/{agentId}', [PageBuilderController::class, 'showByAgent']);
        Route::get('/page/agents/deleted', [PageBuilderController::class, 'deleted'])
            ->middleware(['auth:sanctum', 'agent.active']);
        Route::get('/page/agents/{slug}', [PageBuilderController::class, 'show']);
        Route::get('/page/agents', [PageBuilderController::class, 'index']);
        // PageBuilder public tracking
        Route::post('/page/agents/{slug}/impression', [PageBuilderController::class, 'trackImpression']);
        Route::post('/page/agents/{slug}/click', [PageBuilderController::class, 'trackClick']);
        // Buyer Form (Open House): public fetch by share slug for the client registration page
        Route::get('/buyer-forms/{slug}', [BuyerFormController::class, 'show']);
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
        Route::get('sitemap/modifier-thresholds', [SitemapController::class, 'modifierThresholds']);
        Route::get('sitemap/query-counts', [SitemapController::class, 'queryCounts']);
        Route::get('sitemap/facility-counts', [SitemapController::class, 'facilityCounts']);
        Route::get('sitemap/barangay-counts', [SitemapController::class, 'barangayCounts']);
        Route::get('facilities', [SitemapController::class, 'facilities']);

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
    
        Route::middleware(['auth:sanctum', 'agent.active'])->group(function(){
            Route::get('/client-logins', [UserController::class, 'getClients']);
            Route::get('/authenticate', [UserController::class, 'authenticate']);

            // Expo push tokens + in-app notification feed (mobile app)
            Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
            Route::patch('/notification-preferences', [NotificationController::class, 'updatePreferences']);
            Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

            // Recipient-facing announcements the caller received. The list route
            // is declared before the {announcement} param route so "/announcements"
            // isn't swallowed as an id.
            Route::get('/announcements', [AnnouncementController::class, 'indexForRecipient']);
            Route::get('/announcements/{announcement}', [AnnouncementController::class, 'showForRecipient']);

            Route::post('/user/session-ping', [UserController::class, 'sessionPing']);
            Route::get('/users/search', [UserController::class, 'searchUsers']);
            Route::get('/openai/parse-listing-query', [OpenAIController::class, 'parseListingQuery']);
            Route::post('/openai/classify-photos', [OpenAIController::class, 'classifyListingPhotos']);
            Route::post('/openai/improve-title', [OpenAIController::class, 'improveListingTitle']);
            Route::apiResource('users', UserController::class);
            Route::get('/teams/monthly-listings', [TeamController::class, 'monthlyListings']);
            Route::apiResource('teams', TeamController::class);
            Route::apiResource('team-agents', TeamAgentController::class);
            Route::apiResource('property_attributes', PropertyAttributesController::class);
            Route::apiResource('properties', PropertyController::class);
            Route::apiResource('listings', ListingController::class)->only(['store', 'update', 'destroy']);
            Route::post('/listings/{id}/restore', [ListingController::class, 'restore']);
            // Manually input photos for a removed (photo-migration soft-deleted)
            // listing. Saves to listing.featured_photo + property.photos; the
            // listing stays soft-deleted/removed.
            Route::patch('/listings/{id}/removed-photos', [ListingController::class, 'updateRemovedPhotos']);
            Route::apiResource('full-listing', FullListingController::class)->only(['store', 'show', 'update', 'destroy'])->parameters(['full-listing' => 'listing']);
            Route::patch('/listings/{listing}/visibility', [ListingController::class, 'updateVisibility']);
            // Agent-promoted flyer capture as the listing's og:image (null = default card).
            Route::patch('/listings/{listing}/share-thumbnail', [ListingController::class, 'updateShareThumbnail']);
            Route::patch('/listings/{listing}/status', [ListingController::class, 'updateStatus']);
            Route::patch('/listings/{listing}/featured', [ListingController::class, 'updateIsFeatured']);
            Route::patch('/listings/{listing}/verify', [ListingController::class, 'updateVerification']);
            // Per-listing audit history (admin + team leader of that listing).
            // NOTE: the audit-queue list route lives up in the guest-token group
            // (before `/listings/{slug}`) so its literal segment isn't read as a
            // slug. This two-segment route can't collide, so it stays here.
            Route::get('/listings/{listing}/activity', [ListingController::class, 'activity']);

            // Activity logs (admin-only — enforced in controller)
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::get('/activity-logs/categories', [ActivityLogController::class, 'categories']);
            Route::get('/activity-logs/overview-stats', [ActivityLogController::class, 'overviewStats']);
            Route::get('/activity-logs/storage', [ActivityLogController::class, 'storageOverview']);
            Route::get('/activity-logs/export', [ActivityLogController::class, 'exportLogs']);
            Route::post('/activity-logs/clear', [ActivityLogController::class, 'clearOldLogs']);

            // Feature tokens
            Route::post('/feature-tokens/issue', [FeatureTokenController::class, 'issue']);
            Route::get('/feature-tokens/agent/{agentId}', [FeatureTokenController::class, 'indexForAgent']);
            Route::delete('/feature-tokens/{id}', [FeatureTokenController::class, 'revoke']);
            Route::get('/feature-tokens/my', [FeatureTokenController::class, 'myTokens']);
            Route::post('/feature-tokens/{id}/apply', [FeatureTokenController::class, 'apply']);

            // Admin "login as agent" (impersonation). `start` is admin-only
            // (enforced in the controller); `stop` is called by the impersonated
            // session itself, so it only requires auth:sanctum.
            Route::post('/admin/agents/{agent}/impersonate', [ImpersonationController::class, 'start']);
            Route::post('/admin/impersonate/stop', [ImpersonationController::class, 'stop']);
            Route::get('/my-listings', [ListingController::class, 'myListings']);
            Route::get('/all-listings', [ListingController::class, 'allListings']);
            Route::get('/all-listings/map-markers', [ListingController::class, 'mapMarkers']);
            Route::get('/admin/map-boundaries', [\App\Http\Controllers\BoundaryController::class, 'index']);
            Route::get('/user/dashboard', [ListingController::class, 'dashboard']);
            Route::get('/user/status-by-date', [ListingController::class, 'dashboardStatusByDate']);
            Route::get('/user/agent-demographics', [ListingController::class, 'dashboardAgentDemographics']);
            Route::get('/user/users-by-date', [UserController::class, 'dashboardUsersByDate']);
            Route::get('/user/profile', [UserController::class, 'profile']);
            Route::get('/user/settings', [UserController::class, 'userSettings']);
            Route::post('agents', [AgentController::class, 'store']);
            Route::get('/agent/profile', [AgentController::class, 'profile']);
            // Agent-facing rankings: public-safe top board + the caller's own
            // rank. Open to any authenticated agent (NOT admin-gated), unlike
            // the admin leaderboards/teams/trends endpoints.
            Route::get('/agent/leaderboard', [AgentReviewController::class, 'agentLeaderboard']);
            Route::post('/user/email-change/initiate', [EmailChangeController::class, 'initiateUserEmail']);
            Route::post('/user/email-change/confirm', [EmailChangeController::class, 'confirmUserEmail']);
            Route::post('/agent/lr-email-change/initiate', [EmailChangeController::class, 'initiateLrEmail']);
            Route::post('/agent/lr-email-change/confirm', [EmailChangeController::class, 'confirmLrEmail']);
            Route::patch('agents/{id}/status', [AgentController::class, 'updateStatus']);
            Route::delete('agents/{id}', [AgentController::class, 'destroy']);
            Route::post('agents/{id}/restore', [AgentController::class, 'restore']);
            // Email a Top-10 leaderboard certificate (PNG uploaded by admin) to
            // the agent. Admin-gated in the controller.
            Route::post('agents/{id}/send-certificate', [AgentController::class, 'sendCertificate']);
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/project-list', [ProjectController::class, 'projects']);
            Route::get('/projects/by-province', [ProjectController::class, 'byProvince']);
            Route::get('/projects/insights/by-name', [ProjectController::class, 'byName']);
            Route::get('/projects/insights/detail/{projectKey}', [ProjectController::class, 'insightsDetail'])
                ->where('projectKey', '(project|property):\d+');
            Route::get('/listings/insights/by-province', [ListingController::class, 'insightsByProvince']);
            Route::get('/listings/insights/by-status', [ListingController::class, 'insightsByStatus']);
            Route::get('/listings/insights/by-type', [ListingController::class, 'insightsByType']);
            Route::get('/listings/insights/created', [ListingController::class, 'insightsCreated']);
            Route::get('/listings/insights/summary', [ListingController::class, 'insightsSummary']);
            Route::get('/listings/insights/clusters', [ListingController::class, 'insightsClusters']);
            // Admin "Top Listing Creators" tile (admin-gated in the controller).
            Route::get('/listings/insights/top-creators', [ListingController::class, 'insightsTopCreators']);
            Route::get('/listings/insights/status/{status}', [ListingController::class, 'insightsListingsForStatus'])
                ->where('status', '[a-z_-]+');
            Route::get('/listings/insights/by-city/{city}/ats', [ListingController::class, 'insightsCityAtsListings'])
                ->where('city', '[0-9]+');
            Route::get('/projects/deleted', [ProjectController::class, 'deletedProjects']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::post('/projects/{id}/link-unassociated', [ProjectController::class, 'linkUnassociatedProperty']);
            Route::post('/projects/{id}/link-deleted-properties', [ProjectController::class, 'linkDeletedProjectProperties']);
            Route::post('/projects/{id}/restore', [ProjectController::class, 'restore']);
            Route::patch('/projects/{id}', [ProjectController::class, 'update']);
            Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
            Route::post('/upload', [ImageUploadController::class, 'upload']);
            // Re-host a recovered photo from a remote URL (or file). Separate
            // from /upload so the public uploader stays untouched.
            Route::post('/upload-from-url', [RemovedPhotoUploadController::class, 'upload']);
            // ATS-specific upload: preserves originals ≤5MB, compresses
            // larger files to JPEG ≤5MB without downscaling.
            Route::post('/upload-ats', [ImageUploadController::class, 'uploadAts']);
            Route::post('/upload-pdf', [FileUploadController::class, 'uploadFile']);
            // No-compression uploader (GIF + photos), stored as-is. Used by ads.
            Route::post('/upload-gif', [GifUploadController::class, 'upload']);
            Route::post('/logout', [UserController::class, 'logout']);
            Route::post('/logout-all', [UserController::class, 'logoutAll']);

            // Active login sessions ("Manage devices")
            Route::get('/sessions', [SessionController::class, 'index']);
            Route::delete('/sessions/{id}', [SessionController::class, 'destroy']);
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

            // Buyer Form (Open House) — agent-created registration forms + client submissions.
            // The client "register" POST sits behind auth:sanctum so only logged-in users can
            // submit (anti-spam); identity (name/email) is taken from the token, not the body.
            Route::get('/buyer-forms', [BuyerFormController::class, 'index']);
            Route::post('/buyer-forms', [BuyerFormController::class, 'store']);
            Route::get('/buyer-forms/{id}/registrations', [BuyerFormController::class, 'registrations']);
            Route::delete('/buyer-forms/{id}', [BuyerFormController::class, 'destroy']);
            Route::post('/buyer-forms/{slug}/register', [BuyerFormController::class, 'register']);

    
            // Admin-only: Get In Touch / Contact Us inquiry inbox + replies.
            // GET /admin/inquiries          → paginated list with replies
            // GET /admin/inquiries/{id}     → single inquiry with thread
            // POST /admin/inquiries/{id}/reply → send a reply from info@
            Route::middleware(RoleMiddleware::class . ':admin')->group(function () {
                Route::get('/admin/inquiries', [InquiryController::class, 'index']);
                Route::get('/admin/inquiries-unread-count', [InquiryController::class, 'unreadCount']);
                Route::post('/admin/inquiries/mark-all-read', [InquiryController::class, 'markAllRead']);
                Route::get('/admin/inquiries/{inquiry}', [InquiryController::class, 'show']);
                Route::patch('/admin/inquiries/{inquiry}/read', [InquiryController::class, 'setRead']);
                Route::post('/admin/inquiries/{inquiry}/reply', [InquiryController::class, 'reply']);

                // Client Demographics — gender + age brackets of registered
                // clients (admin-only; agents have their own non-gated stat).
                Route::get('/user/client-demographics', [ListingController::class, 'dashboardClientDemographics']);
                // Registered-agent counterpart for the unified Demographics
                // page (ALL non-deleted agents by registration date — distinct
                // from /user/agent-demographics, which covers transacting
                // agents only).
                Route::get('/user/agent-demographics-registered', [ListingController::class, 'dashboardAgentDemographicsRegistered']);
                // The individuals behind the aggregates — "View list" modal.
                Route::get('/user/demographics-people', [ListingController::class, 'dashboardDemographicsPeople']);

                // Inquiry Analytics — deep drill-down over listing inquiries
                // (chats type='listing'): overview breakdowns, hierarchical
                // location drill-down, and top inquiring clients.
                Route::get('/admin/inquiry-analytics/overview', [InquiryAnalyticsController::class, 'overview']);
                Route::get('/admin/inquiry-analytics/locations', [InquiryAnalyticsController::class, 'locations']);
                Route::get('/admin/inquiry-analytics/origins', [InquiryAnalyticsController::class, 'origins']);
                Route::get('/admin/inquiry-analytics/clients', [InquiryAnalyticsController::class, 'clients']);
                Route::get('/admin/inquiry-analytics/heatmap', [InquiryAnalyticsController::class, 'heatmap']);
                Route::get('/admin/inquiry-analytics/clusters', [InquiryAnalyticsController::class, 'clusters']);
                Route::get('/admin/inquiry-analytics/inquiries', [InquiryAnalyticsController::class, 'inquiries']);
                Route::get('/admin/inquiry-analytics/listings', [InquiryAnalyticsController::class, 'listings']);
                Route::get('/admin/inquiry-analytics/listing-inquiries', [InquiryAnalyticsController::class, 'listingInquiries']);

                // Audience Insights — client/visitor counts (admin-only).
                Route::get('/user/audience-insights', [AudienceInsightsController::class, 'show']);
                // Geography breakdown (own date range so the card filters independently).
                Route::get('/user/audience-insights/geography', [AudienceInsightsController::class, 'geographyShow']);

                // App version download links (CRUD for the web downloads page).
                Route::post('/admin/app-versions', [AppVersionController::class, 'store']);
                Route::patch('/admin/app-versions/{id}', [AppVersionController::class, 'update']);
                Route::delete('/admin/app-versions/{id}', [AppVersionController::class, 'destroy']);

                // Broadcast push notifications (announcement / maintenance /
                // custom) to a fleet segment, with per-announcement read +
                // device analytics.
                Route::get('/admin/announcements', [AnnouncementController::class, 'index']);
                Route::post('/admin/announcements', [AnnouncementController::class, 'store']);
                Route::get('/admin/announcements/{announcement}', [AnnouncementController::class, 'show']);
                Route::get('/admin/announcements/{announcement}/stats', [AnnouncementController::class, 'stats']);

                // Mobile Statistics page — who's on the Expo app, their devices,
                // and their push/email config (which admins can edit here).
                Route::get('/admin/mobile-stats', [MobileStatisticsController::class, 'stats']);
                Route::get('/admin/mobile-users', [MobileStatisticsController::class, 'users']);
                Route::patch('/admin/users/{user}/notification-preferences', [NotificationController::class, 'adminUpdatePreferences']);
            });

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
        Route::middleware(['auth:sanctum', 'agent.active'])->group(function(){
            // Aggregate counts for /admin/chat-statistics. Must precede the
            // chats apiResource — otherwise `/chats/stats` matches `show`
            // and tries to bind `stats` as a Chat model id.
            Route::get('chats/stats', [ChatController::class, 'stats']);
            Route::apiResource('chats', ChatController::class)->only(['index', 'store', 'show', 'destroy']);
            Route::apiResource('conversations', ConversationController::class)->only(['index', 'show']);
            Route::post('conversations/{conversation}/mark-read', [ConversationController::class, 'markRead']);
            Route::post('conversations/{conversation}/accept', [ConversationController::class, 'accept']);
            Route::post('conversations/{conversation}/reject', [ConversationController::class, 'reject']);
            Route::post('conversations/{conversation}/close', [ConversationController::class, 'close']);
            Route::post('conversations/{conversation}/reopen', [ConversationController::class, 'reopen']);
            // Bulk Accept / Reject for moderators. Per-conversation
            // authorization happens inside the controller so a TL
            // submitting a chat outside their team is reported in
            // the response's `skipped` array, not blanket-403 for
            // the whole batch.
            Route::post('conversations/bulk-action', [ConversationController::class, 'bulkAction']);
            // Per-participant archive + trash + permanent delete. State
            // machine lives on the conversation_users pivot; see
            // ChatController::mutateViewerPivot. `purge` is the only
            // terminal action — once set, the chat is hidden from all
            // views for that viewer (the row stays in the DB).
            Route::post('chats/{chat}/archive',   [ChatController::class, 'archive']);
            Route::post('chats/{chat}/unarchive', [ChatController::class, 'unarchive']);
            Route::post('chats/{chat}/trash',     [ChatController::class, 'trash']);
            Route::post('chats/{chat}/restore',   [ChatController::class, 'restore']);
            Route::post('chats/{chat}/purge',     [ChatController::class, 'purge']);
            // Admin-only hard delete: permanently removes EVERY chat owned by
            // the given user (their inquiries/threads) for all participants.
            Route::delete('chats/purge-by-user/{user}', [ChatController::class, 'purgeByUser']);
            Route::apiResource('messages', MessageController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::post('messages/{message}/reactions', [ReactionController::class, 'toggle']);
            Route::get('blocked-users', [BlockedUserController::class, 'index']);
            Route::get('blocked-users/check', [BlockedUserController::class, 'check']);
            Route::post('blocked-users', [BlockedUserController::class, 'store']);
            Route::delete('blocked-users/{blockedUser}', [BlockedUserController::class, 'destroy']);

            // Agent reviews — client → agent star ratings + agent right-of-reply.
            // Rate-prompt eligibility probe (used by the chat UI to decide when
            // to surface the inline RateAgentInlineCard).
            Route::get('conversations/{conversation}/rate-prompt-eligibility',
                [ConversationController::class, 'ratePromptEligibility']);
            Route::post('conversations/{conversation}/rate-prompt-dismiss',
                [AgentReviewController::class, 'dismissPrompt']);
            // The chat owner's review of the assigned agent (full object) —
            // readable by any conversation viewer (client prefill + agent /
            // observers seeing the persisted review in-thread).
            Route::get('conversations/{conversation}/client-review',
                [AgentReviewController::class, 'clientReview']);
            // Assigned agent / moderator nudges the client to UPDATE it.
            Route::post('conversations/{conversation}/request-review',
                [AgentReviewController::class, 'requestReview']);

            // Client-side CRUD. Upserts via unique (client_user_id, agent_user_id).
            Route::post('agent-reviews', [AgentReviewController::class, 'store']);
            Route::put('agent-reviews/{review}', [AgentReviewController::class, 'update']);
            Route::delete('agent-reviews/{review}', [AgentReviewController::class, 'destroy']);
            Route::post('agent-reviews/{review}/response',
                [AgentReviewController::class, 'storeResponse']);
            Route::delete('agent-reviews/{review}/response',
                [AgentReviewController::class, 'destroyResponse']);
            Route::post('agent-reviews/{review}/helpful',
                [AgentReviewController::class, 'toggleHelpful']);

            // Batched eligibility lookup used by /client/listing-inquiries
            // to paint per-row "Rate" chips + the top banner without
            // running N probes (one per row).
            Route::get('agent-reviews/my-eligible-inquiries',
                [AgentReviewController::class, 'myEligibleInquiries']);

            // Authored-review history for /client/my-reviews. Returns
            // every status (visible / hidden / flagged) so the author
            // can see admin moderation transparently. mineSummary
            // powers the header stat strip.
            Route::get('agent-reviews/mine',
                [AgentReviewController::class, 'mine']);
            Route::get('agent-reviews/mine/summary',
                [AgentReviewController::class, 'mineSummary']);

            // Manual-entry probe used by the agent profile "Rate this
            // Agent" button. Returns can_submit + conversation_id +
            // existing_review_id so the frontend can render the right
            // affordance + open the dialog with the right context.
            Route::get('agent-reviews/can-submit-for-agent/{agentUserId}',
                [AgentReviewController::class, 'canSubmitForAgent'])
                ->whereNumber('agentUserId');

            // Admin moderation surface — /admin/agent-feedback consumes these.
            Route::get('admin/agent-reviews', [AgentReviewController::class, 'adminIndex']);
            Route::get('admin/agent-reviews/summary', [AgentReviewController::class, 'adminSummary']);
            Route::get('admin/agent-reviews/leaderboards',
                [AgentReviewController::class, 'leaderboards']);
            Route::get('admin/agent-reviews/teams',
                [AgentReviewController::class, 'teamsRollup']);
            Route::get('admin/agent-reviews/reviewers',
                [AgentReviewController::class, 'topReviewers']);
            Route::get('admin/agent-reviews/trends',
                [AgentReviewController::class, 'trends']);
            Route::patch('agent-reviews/{review}/visibility',
                [AgentReviewController::class, 'setVisibility']);
        });
    });
});

// Public agent-review reads (no auth required). Live OUTSIDE the chat
// throttle group so they're cacheable and not rate-shaped to chat
// traffic. {agent} here is the user_id of the agent (matches the
// existing /agents/{id} routes' resolution shape).
Route::middleware('strip.tags')->group(function () {
    Route::get('/agents/{agent}/reviews', [AgentReviewController::class, 'index']);
    Route::get('/agents/{agent}/reviews/summary', [AgentReviewController::class, 'summary']);
});
// Background Jobs
Route::post('/listings/update-batch', [BackgroundJobController::class, 'execute']);
