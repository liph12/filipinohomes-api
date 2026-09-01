<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AdCampaignController;
use App\Http\Controllers\AdController;
use App\Http\Controllers\AdPlacementController;
use App\Http\Controllers\AdPreviewController;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AdSectionController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentReviewController;
use App\Http\Controllers\AmenityController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\AudienceInsightsController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BackgroundJobController;
use App\Http\Controllers\BlockedUserController;
use App\Http\Controllers\BlogCategoryController;
use App\Http\Controllers\BoundaryController;
use App\Http\Controllers\BuyerFormController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\FacilityAdminController;
use App\Http\Controllers\FacilityCandidateController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FeatureTokenController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FullListingController;
use App\Http\Controllers\FurnishingController;
use App\Http\Controllers\GenerateDescriptionController;
use App\Http\Controllers\GifUploadController;
use App\Http\Controllers\GuestTokenController;
use App\Http\Controllers\HomesPhNewsController;
use App\Http\Controllers\ImageUploadController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InquiryAnalyticsController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\MagazineController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MobileStatisticsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\OpenAIController;
use App\Http\Controllers\PageBuilderController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PropertyAttributesController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PropertySubtypeController;
use App\Http\Controllers\PropertyTypeController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\PublicAdController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReelController;
use App\Http\Controllers\RemovedPhotoUploadController;
use App\Http\Controllers\SeoCommandController;
use App\Http\Controllers\SeoInventoryController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TeamAgentController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VisitController;
use App\Http\Middleware\RoleMiddleware;
use App\Natcon\Http\Controllers\AdminController as NatconAdminController;
use App\Natcon\Http\Controllers\AnnouncementReactionController as NatconReactionController;
use App\Natcon\Http\Controllers\FormFieldController as NatconFormFieldController;
use App\Natcon\Http\Controllers\GalleryController as NatconGalleryController;
use App\Natcon\Http\Controllers\LandingController as NatconLandingController;
use App\Natcon\Http\Controllers\PhotographerGalleryController as NatconPhotographerController;
use App\Natcon\Http\Controllers\PublicController as NatconPublicController;
use App\Natcon\Http\Controllers\SponsorCaptionController as NatconSponsorCaptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('strip.tags')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login', [UserController::class, 'login']);
        Route::post('/auth/google', [GoogleAuthController::class, 'authenticate']);
        Route::post('/auth/dev-login', [UserController::class, 'devLogin']);
        Route::post('/auth-send-otp', [UserController::class, 'authWithOtp']);
        Route::post('/auth-request-verify-otp', [UserController::class, 'authRequestVerifyOtp']);
    });

    Route::middleware('throttle:api')->group(function () {
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

        // ── NATCON 2026 awardee photo confirmation ──────────────────────────
        // Reached from a link in the invite email. Identity comes from the
        // per-recipient signed token in `t`, NOT from the ?email= in the URL
        // (that's decorative, so the page can paint a name before this call
        // returns — mail clients mangle query strings, and matching on it would
        // turn cosmetic damage into a false 404).
        //
        // ⚠️ None of these may answer 401: the frontend axios interceptor treats
        //    a message-keyed 401 as a dead session and clears the user's login.
        //    Link problems are 404/410, validation is 422.
        // ⚠️ Nothing here mutates on GET. Outlook SafeLinks and friends fetch
        //    every URL in an email within seconds of delivery, so a GET "retain"
        //    would mark hundreds of people as responded before a human saw it.
        // Event facts only — name, dates, venue, deadline. No PII, so it sits
        // outside the guest-token group alongside the other genuinely public
        // reference endpoints (provinces, app-config, buyer-forms/{slug}). The
        // /natcon landing page fetches this during SSR.
        Route::get('/natcon/event', [NatconPublicController::class, 'event']);

        // The rest of the landing page's content, outside the token group for the
        // SAME reason /natcon/event is: the page is server-rendered and
        // indexable. SSR carries no guest token and neither does Googlebot, so
        // putting these behind verify.guest.token would 401 the only two
        // consumers they exist for.
        //
        // `recaps` is declared before `{year}` so the literal segment is never
        // bound as a year.
        Route::get('/natcon/recaps', [NatconLandingController::class, 'recaps']);
        Route::get('/natcon/{year}/announcements', [NatconLandingController::class, 'announcements'])
            ->whereNumber('year');
        Route::get('/natcon/{year}/sponsors', [NatconLandingController::class, 'sponsors'])
            ->whereNumber('year');
        Route::get('/natcon/{year}/gallery', [NatconGalleryController::class, 'gallery'])
            ->whereNumber('year');
        // PUBLIC photo albums (/albums, /albums/{slug} on the site) — gallery
        // rows with no convention. Same token-less reasoning as the NATCON
        // reads above: SSR and Googlebot are the consumers.
        Route::get('/albums', [NatconGalleryController::class, 'publicAlbums']);
        Route::get('/albums/{slug}', [NatconGalleryController::class, 'publicAlbum'])
            ->where('slug', '[a-z0-9-]+');
        // Decorative frames a visitor can lay over an album's photos —
        // token-less like the reads above (the frame picker is public UI).
        Route::get('/albums/{slug}/frames', [NatconGalleryController::class, 'publicAlbumFrames'])
            ->where('slug', '[a-z0-9-]+');
        // Reaction TALLIES, outside the token group for the same reason as the
        // feed: the caller is the frontend's caching proxy, which runs
        // server-side and carries no guest token. Counts only — no visitor is
        // identifiable in the response, because that response is shared.
        Route::get('/natcon/{year}/reactions', [NatconReactionController::class, 'index'])
            ->whereNumber('year');

        // Everything token-bound goes behind verify.guest.token. That is a bot
        // speed bump, not a security control — POST /api/guest-token is itself
        // public — but it's free, since the frontend axios instance attaches the
        // header automatically. The real control is the per-recipient token.
        Route::middleware('verify.guest.token')->group(function () {
            Route::get('/natcon/profile', [NatconPublicController::class, 'profile']);
            Route::post('/natcon/respond', [NatconPublicController::class, 'respond'])
                ->middleware('throttle:20,1');
            Route::post('/natcon/photo', [NatconPublicController::class, 'photo'])
                ->middleware('throttle:natcon-upload');
            // Removing a photo to free a slot. Cheaper than an upload, so it takes
            // the ordinary limit rather than the upload throttle — but it still
            // writes, so it is not on the profile read's budget either.
            Route::delete('/natcon/photo', [NatconPublicController::class, 'deletePhoto'])
                ->middleware('throttle:20,1');
            // Which of the photos already on file they are keeping. Declarative:
            // the body is the whole set, not a delta.
            Route::post('/natcon/photos/keep', [NatconPublicController::class, 'keepPhotos'])
                ->middleware('throttle:20,1');

            Route::post('/natcon/form', [NatconPublicController::class, 'form'])
                ->middleware('throttle:20,1');
            // Deliberately a useless oracle — same body either way. Tight limit
            // because it's the one endpoint keyed by email rather than by token.
            Route::post('/natcon/resend-link', [NatconPublicController::class, 'resendLink'])
                ->middleware('throttle:3,60');

            // Reacting to an announcement. Anonymous — the actor is the browser's
            // persistent visitor id, not a login — so the guest token is the only
            // bot speed bump available and the throttle does the real work.
            Route::post('/natcon/announcements/{announcement}/reactions',
                [NatconReactionController::class, 'store'])
                ->middleware('throttle:natcon-react');

            // "Find my photos" on /albums — selfies in, live public-album
            // photos out. Each hit is up to 5 Rekognition calls, so a tight
            // per-IP throttle on top of the guest token.
            Route::post('/albums/face-search', [NatconGalleryController::class, 'publicFaceSearch'])
                ->middleware('throttle:10,1');

            // ── Photographer upload invites ─────────────────────────────────
            // Hired photographers upload event photos through a tokenized
            // link (t) minted from the admin Gallery tab — no account. Same
            // contract as the awardee routes above: link problems are 404/410,
            // validation 422, NEVER 401, and no GET mutates (SafeLinks). The
            // literal 'upload-invite' segment can't collide with /natcon/{year}
            // — that route is whereNumber'd.
            Route::get('/natcon/upload-invite', [NatconPhotographerController::class, 'state']);
            Route::post('/natcon/upload-invite/albums', [NatconPhotographerController::class, 'storeAlbum'])
                ->middleware('throttle:60,1');
            Route::patch('/natcon/upload-invite/albums/{album}', [NatconPhotographerController::class, 'updateAlbum'])
                ->middleware('throttle:60,1');
            // Sized for throughput, not suspicion — hundreds of files per
            // session is the NORMAL case on event day.
            Route::post('/natcon/upload-invite/photos', [NatconPhotographerController::class, 'storePhoto'])
                ->middleware('throttle:gallery-invite-upload');
            Route::patch('/natcon/upload-invite/photos/{photo}', [NatconPhotographerController::class, 'updatePhoto'])
                ->middleware('throttle:60,1');
            Route::delete('/natcon/upload-invite/photos/{photo}', [NatconPhotographerController::class, 'destroyPhoto'])
                ->middleware('throttle:60,1');
        });
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
        Route::get('sitemap/market-stats', [SitemapController::class, 'marketStats']);
        Route::get('sitemap/market-stats-history', [SitemapController::class, 'marketStatsHistory']);
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

        Route::middleware(['auth:sanctum', 'agent.active'])->group(function () {
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
            // Everyone online across all roles (chat/messaging strip).
            Route::get('/online-users', [UserController::class, 'onlineUsers']);
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
            // Single-row detail (incl. captured email body). Numeric-constrained
            // so it never shadows the literal /activity-logs/* routes above.
            Route::get('/activity-logs/{audit}', [ActivityLogController::class, 'show'])->whereNumber('audit');
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
            // Reel Maker usage tracking (video is built client-side; this only
            // records open/preview/generate/share into the audit trail).
            Route::post('/reels/events', [ReelController::class, 'logEvent']);
            Route::get('/all-listings/map-markers', [ListingController::class, 'mapMarkers']);
            Route::get('/admin/map-boundaries', [BoundaryController::class, 'index']);
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
            // High-quality uploader (2560px WebP q88, no byte cap) for the
            // page builder's photography — /upload's 50KB budget is for
            // logos/small assets and crushes banners and portraits.
            Route::post('/upload-hq', [ImageUploadController::class, 'uploadHq']);
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
            Route::middleware(RoleMiddleware::class.':admin')->group(function () {
                // ── NATCON 2026 campaign admin ──────────────────────────────
                // ⚠️ /send-invites does NOT send. It writes natcon_outbox rows
                //    and returns; natcon:drain-outbox does the sending, paced by
                //    the scheduler. Sending inline would 524 behind Cloudflare
                //    partway through and the admin would click Send again.
                Route::get('/admin/natcon/events', [NatconAdminController::class, 'events']);
                // Start a new convention year (clones the previous year's questions).
                Route::post('/admin/natcon/events', [NatconAdminController::class, 'storeEvent']);
                Route::patch('/admin/natcon/events/{event}', [NatconAdminController::class, 'updateEvent']);
                Route::get('/admin/natcon/stats', [NatconAdminController::class, 'stats']);

                Route::get('/admin/natcon/recipients', [NatconAdminController::class, 'recipients']);
                Route::post('/admin/natcon/recipients', [NatconAdminController::class, 'storeRecipients']);
                // Pulls the awardee roster from LR's qualifiers list. Throttled
                // low: it is a ~288KB external fetch and nobody needs it often.
                Route::post('/admin/natcon/recipients/sync-lr', [NatconAdminController::class, 'syncQualifiers'])
                    ->middleware('throttle:6,1');
                Route::get('/admin/natcon/recipients/{recipient}', [NatconAdminController::class, 'showRecipient']);
                Route::patch('/admin/natcon/recipients/{recipient}', [NatconAdminController::class, 'updateRecipient']);
                Route::delete('/admin/natcon/recipients/{recipient}', [NatconAdminController::class, 'destroyRecipient']);
                Route::post('/admin/natcon/recipients/{recipient}/refresh-lr', [NatconAdminController::class, 'refreshLr'])
                    ->middleware('throttle:30,1');
                // Rotates the token — any previously emailed link stops working.
                Route::post('/admin/natcon/recipients/{recipient}/issue-link', [NatconAdminController::class, 'issueLink'])
                    ->middleware('throttle:20,1');
                // Clears send history + response so an invite can go out again.
                // Throttled low: this is a testing and correction tool, and a
                // loop hitting it would be wiping response data, not reading it.
                Route::post('/admin/natcon/recipients/{recipient}/reset', [NatconAdminController::class, 'resetRecipient'])
                    ->middleware('throttle:20,1');
                // Rules the photo on file unusable, forcing a fresh submission.
                Route::post('/admin/natcon/recipients/{recipient}/photo-policy', [NatconAdminController::class, 'setPhotoPolicy'])
                    ->middleware('throttle:30,1');

                Route::post('/admin/natcon/preflight', [NatconAdminController::class, 'preflight']);
                Route::post('/admin/natcon/send-invites', [NatconAdminController::class, 'sendInvites'])
                    ->middleware('throttle:6,1');
                Route::get('/admin/natcon/batches/{batchId}', [NatconAdminController::class, 'batch']);

                Route::get('/admin/natcon/submissions', [NatconAdminController::class, 'submissions']);
                Route::patch('/admin/natcon/photo-submissions/{submission}', [NatconAdminController::class, 'reviewPhoto']);
                Route::get('/admin/natcon/suppressions', [NatconAdminController::class, 'suppressions']);
                Route::post('/admin/natcon/suppressions', [NatconAdminController::class, 'suppress']);

                // Form builder — edit the questions/choices the awardee page renders.
                // Reorder is registered BEFORE /{field} so "reorder" is never bound
                // as a model id.
                Route::get('/admin/natcon/form-fields', [NatconFormFieldController::class, 'index']);
                Route::post('/admin/natcon/form-fields', [NatconFormFieldController::class, 'store']);
                Route::post('/admin/natcon/form-fields/reorder', [NatconFormFieldController::class, 'reorder']);
                Route::patch('/admin/natcon/form-fields/{field}', [NatconFormFieldController::class, 'update']);
                Route::delete('/admin/natcon/form-fields/{field}', [NatconFormFieldController::class, 'destroy']);

                // AI sponsor thank-you caption for the poster tool. Admin-only. Throttled:
                // each hit is a real OpenAI call.
                Route::post('/admin/natcon/sponsor-caption', [NatconSponsorCaptionController::class, 'generate'])
                    ->middleware('throttle:15,1');

                // ── Landing-page content ─────────────────────────────────────
                // Editors as well as admins: this is marketing copy and a list
                // of video links, not the send machinery. Kept inside the same
                // auth group and re-gated on the line below.
                Route::middleware(RoleMiddleware::class.':admin,editor')->group(function () {
                    Route::get('/admin/natcon/announcements', [NatconLandingController::class, 'adminAnnouncements']);
                    Route::post('/admin/natcon/announcements', [NatconLandingController::class, 'storeAnnouncement']);
                    Route::patch('/admin/natcon/announcements/{announcement}', [NatconLandingController::class, 'updateAnnouncement']);
                    Route::delete('/admin/natcon/announcements/{announcement}', [NatconLandingController::class, 'destroyAnnouncement']);

                    Route::get('/admin/natcon/recaps', [NatconLandingController::class, 'adminRecaps']);
                    Route::post('/admin/natcon/recaps', [NatconLandingController::class, 'storeRecap']);
                    Route::patch('/admin/natcon/recaps/{recap}', [NatconLandingController::class, 'updateRecap']);
                    Route::delete('/admin/natcon/recaps/{recap}', [NatconLandingController::class, 'destroyRecap']);

                    Route::get('/admin/natcon/sponsors', [NatconLandingController::class, 'adminSponsors']);
                    Route::post('/admin/natcon/sponsors', [NatconLandingController::class, 'storeSponsor']);
                    Route::patch('/admin/natcon/sponsors/{sponsor}', [NatconLandingController::class, 'updateSponsor']);
                    Route::delete('/admin/natcon/sponsors/{sponsor}', [NatconLandingController::class, 'destroySponsor']);

                    Route::get('/admin/natcon/gallery', [NatconGalleryController::class, 'adminGallery']);
                    // Photographer upload invites: mint/copy/rotate/revoke the
                    // tokenized links the portal above consumes. Registered
                    // BEFORE /gallery/{photo} so 'invites' is never bound as a
                    // photo id.
                    Route::get('/admin/natcon/gallery/invites', [NatconGalleryController::class, 'invites']);
                    Route::post('/admin/natcon/gallery/invites', [NatconGalleryController::class, 'storeInvite']);
                    Route::patch('/admin/natcon/gallery/invites/{invite}', [NatconGalleryController::class, 'updateInvite']);
                    Route::post('/admin/natcon/gallery/invites/{invite}/link', [NatconGalleryController::class, 'inviteLink']);
                    Route::post('/admin/natcon/gallery/invites/{invite}/reissue', [NatconGalleryController::class, 'reissueInvite']);
                    Route::post('/admin/natcon/gallery/invites/{invite}/revoke', [NatconGalleryController::class, 'revokeInvite']);
                    // Secondary albums (folders) inside one convention's
                    // gallery — the convention is the primary album; these are
                    // the single level under it (per photographer/company).
                    // Registered BEFORE /gallery/{photo} so "albums" is never
                    // bound as a photo id.
                    // Face search over the curated gallery (its own Rekognition
                    // collection — see NatconEvent::galleryCollectionId()).
                    // Also registered before /gallery/{photo}.
                    Route::post('/admin/natcon/gallery/face-search', [NatconGalleryController::class, 'faceSearch']);
                    Route::get('/admin/natcon/gallery/albums', [NatconGalleryController::class, 'albums']);
                    Route::post('/admin/natcon/gallery/albums', [NatconGalleryController::class, 'storeAlbum']);
                    Route::patch('/admin/natcon/gallery/albums/{album}', [NatconGalleryController::class, 'updateAlbum']);
                    Route::delete('/admin/natcon/gallery/albums/{album}', [NatconGalleryController::class, 'destroyAlbum']);
                    // Throttled: each hit is a real image encode plus two S3
                    // writes. The natcon-upload limiter is keyed on the guest
                    // token and everyone here is authed without one, so it
                    // would funnel every admin into a single 'anon' bucket.
                    // 120/min: the admin uploader runs 5 photos in parallel and
                    // retries a 429 after Retry-After, so this is a ceiling on
                    // sustained abuse, not the pace of a normal bulk upload.
                    Route::post('/admin/natcon/gallery', [NatconGalleryController::class, 'storeGalleryPhoto'])
                        ->middleware('throttle:120,1');
                    Route::post('/admin/natcon/gallery/{photo}/reindex', [NatconGalleryController::class, 'reindexGalleryPhoto']);
                    Route::patch('/admin/natcon/gallery/{photo}', [NatconGalleryController::class, 'updateGalleryPhoto']);
                    Route::delete('/admin/natcon/gallery/{photo}', [NatconGalleryController::class, 'destroyGalleryPhoto']);

                    // PUBLIC albums admin — the same controller, the same
                    // album/photo rules, but `scope=public` makes resolveEvent()
                    // yield null so every read/write hits rows with no
                    // convention (see GalleryController::guardScope). Literal
                    // segments (photos, face-search) are registered before
                    // /{album} so they are never bound as an album id.
                    Route::group(['prefix' => '/admin/albums'], function () {
                        $scope = ['scope' => 'public'];
                        Route::get('/photos', [NatconGalleryController::class, 'adminGallery'])->setDefaults($scope);
                        // Same 120/min ceiling as the NATCON gallery upload above.
                        Route::post('/photos', [NatconGalleryController::class, 'storeGalleryPhoto'])
                            ->middleware('throttle:120,1')->setDefaults($scope);
                        Route::post('/photos/{photo}/reindex', [NatconGalleryController::class, 'reindexGalleryPhoto'])->setDefaults($scope);
                        Route::patch('/photos/{photo}', [NatconGalleryController::class, 'updateGalleryPhoto'])->setDefaults($scope);
                        Route::delete('/photos/{photo}', [NatconGalleryController::class, 'destroyGalleryPhoto'])->setDefaults($scope);
                        Route::post('/face-search', [NatconGalleryController::class, 'faceSearch'])->setDefaults($scope);
                        // Frames — decorative PNG overlays visitors can put on
                        // an album's photos. 'frames/{frame}' (two segments)
                        // can never bind as /{album} (one segment), but it
                        // stays with the literals above the wildcard per
                        // house style.
                        Route::patch('/frames/{frame}', [NatconGalleryController::class, 'updateAlbumFrame'])->setDefaults($scope);
                        Route::delete('/frames/{frame}', [NatconGalleryController::class, 'destroyAlbumFrame'])->setDefaults($scope);
                        Route::get('/{album}/frames', [NatconGalleryController::class, 'albumFrames'])->setDefaults($scope);
                        Route::post('/{album}/frames', [NatconGalleryController::class, 'storeAlbumFrame'])
                            ->middleware('throttle:30,1')->setDefaults($scope);
                        Route::get('/', [NatconGalleryController::class, 'albums'])->setDefaults($scope);
                        Route::post('/', [NatconGalleryController::class, 'storeAlbum'])->setDefaults($scope);
                        Route::patch('/{album}', [NatconGalleryController::class, 'updateAlbum'])->setDefaults($scope);
                        Route::delete('/{album}', [NatconGalleryController::class, 'destroyAlbum'])->setDefaults($scope);
                    });
                });

                // Manual "send me today's report" from System Users — a test
                // hook for the boss activity digest; no schedule sends it yet.
                Route::post('/admin/reports/activity/send', [AdminReportController::class, 'sendActivityReport'])
                    ->middleware('throttle:10,1');

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
                Route::get('/admin/inquiry-analytics/agents', [InquiryAnalyticsController::class, 'agents']);
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

                // ── SEO Manage page (admin) ─────────────────────────────
                // Overview inventory (per-tier counts + freshness, cached).
                Route::get('/admin/seo/overview', [SeoInventoryController::class, 'overview']);

                // Curated "near {facility}" registry CRUD. Slug is server-
                // generated; renames go through /rebrand (alias + 301
                // invariant); DELETE soft-retires (never hard-deletes).
                Route::get('/admin/seo/facilities', [FacilityAdminController::class, 'index']);
                Route::post('/admin/seo/facilities', [FacilityAdminController::class, 'store']);
                Route::post('/admin/seo/facilities/preview-count', [FacilityAdminController::class, 'previewCount']);
                Route::get('/admin/seo/facilities/{facility}', [FacilityAdminController::class, 'show']);
                Route::patch('/admin/seo/facilities/{facility}', [FacilityAdminController::class, 'update']);
                Route::delete('/admin/seo/facilities/{facility}', [FacilityAdminController::class, 'deactivate']);
                Route::post('/admin/seo/facilities/{facility}/activate', [FacilityAdminController::class, 'activate']);
                Route::post('/admin/seo/facilities/{facility}/rebrand', [FacilityAdminController::class, 'rebrand']);
                Route::post('/admin/seo/facilities/{facility}/recompute', [FacilityAdminController::class, 'recompute']);
                Route::post('/admin/seo/facilities/{facility}/ping-indexnow', [FacilityAdminController::class, 'pingIndexNow']);
                Route::post('/admin/seo/facilities/{facility}/geocode', [FacilityAdminController::class, 'geocode'])
                    ->middleware('throttle:10,1'); // Google bills per lookup

                // Scanner-discovered candidates review queue (approve creates
                // a live Facility + recompute; dismiss reversible via restore).
                Route::get('/admin/seo/facility-candidates', [FacilityCandidateController::class, 'index']);
                Route::get('/admin/seo/facility-candidates/map', [FacilityCandidateController::class, 'mapData']);
                Route::post('/admin/seo/facility-candidates/bulk', [FacilityCandidateController::class, 'bulk']);
                Route::post('/admin/seo/facility-candidates/{candidate}/approve', [FacilityCandidateController::class, 'approve']);
                Route::post('/admin/seo/facility-candidates/{candidate}/dismiss', [FacilityCandidateController::class, 'dismiss']);
                Route::post('/admin/seo/facility-candidates/{candidate}/restore', [FacilityCandidateController::class, 'restore']);

                // SEO pipeline commands: registry + last/next runs, queued
                // manual trigger (RunSeoCommand — never synchronous), history.
                Route::get('/admin/seo/commands', [SeoCommandController::class, 'index']);
                Route::post('/admin/seo/commands/{command}/run', [SeoCommandController::class, 'trigger'])
                    ->middleware('throttle:6,1');
                Route::get('/admin/seo/runs', [SeoCommandController::class, 'runs']);
                Route::get('/admin/seo/runs/{run}', [SeoCommandController::class, 'showRun']);
            });

            // Magazine, Office & Ad management (admin + editor only)
            Route::middleware(RoleMiddleware::class.':admin,editor')->group(function () {
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

    Route::middleware('throttle:chat')->group(function () {
        Route::middleware(['auth:sanctum', 'agent.active'])->group(function () {
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
            Route::post('chats/{chat}/archive', [ChatController::class, 'archive']);
            Route::post('chats/{chat}/unarchive', [ChatController::class, 'unarchive']);
            Route::post('chats/{chat}/trash', [ChatController::class, 'trash']);
            Route::post('chats/{chat}/restore', [ChatController::class, 'restore']);
            Route::post('chats/{chat}/purge', [ChatController::class, 'purge']);
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
