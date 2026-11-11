<?php

use App\Services\Seo\SeoCommandRegistry;
use App\Services\Seo\SeoCommandRunRecorder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('listings:expire-featured')->daily();
Schedule::command('ats:expiry-reminders')->dailyAt('00:00')->withoutOverlapping();
Schedule::command('agents:recompute-response-metrics')->hourly()->withoutOverlapping();

// Automatic inquiry approver — admin-configured in System Settings (days +
// optional hour window, Asia/Manila). Gating happens INSIDE the command
// (InquiryAutoApprovalService::isWindowOpen), not here, so a settings change
// takes effect on the next tick with no deploy. withoutOverlapping(10) caps
// a stuck run's file-lock at 10 minutes, same guard as everything else in
// this file — the production scheduler uses file-based mutexes that can be
// left behind by a crashed run (see the api2-ops footgun notes).
Schedule::command('inquiries:auto-approve')->everyFiveMinutes()->withoutOverlapping(10);

// Admin Mailbox index: copy the shared mailbox's website emails into the
// database so /admin/mailbox doesn't log in to the mail server per click.
// If this stops, the Mailbox falls back to live mail-server reads after 10 min.
Schedule::command('mailbox:sync')->everyMinute()->withoutOverlapping(5);

// Nightly boss digest — today's site activity to every un-muted admin, at the
// close of the Manila day. `php artisan reports:send-activity you@x.com` for a
// one-off test send.
Schedule::command('reports:send-activity')
    ->dailyAt('23:59')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// Daily staff-birthdays digest — in the morning so today's celebrants can
// still be greeted. Same all-admins recipient policy as the activity report.
Schedule::command('reports:send-birthdays')
    ->dailyAt('07:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// Daily Website Analytics (GA4) email — recipients + send time are admin-
// configured (settings KV, Website Analytics dashboard); sendTime() falls
// back to 07:30 and never throws, since this file runs on every artisan
// invocation. The command itself no-ops when disabled/unconfigured.
Schedule::command('reports:send-website-analytics')
    ->dailyAt(\App\Services\Reports\WebsiteAnalyticsReportService::sendTime())
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// Personal greeting + poster TO each active agent celebrating today. Same
// 07:00 slot as the admin digest; renders/uploads the poster per agent so
// it runs in the background.
Schedule::command('birthdays:send-greetings')
    ->dailyAt('07:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->runInBackground();

// Fills agents.birthdate from Leuterio Realty for agents who have never
// logged in — the login-time backfill is the only other source, so coverage
// tracks logins rather than headcount and the greeting above would otherwise
// reach almost nobody. Chunked + paced to stay under LR's 60 req/min; drains
// over a couple of days, then no-ops cheaply forever.
Schedule::command('birthdays:backfill-birthdates')
    ->hourly()
    ->withoutOverlapping();

// SEO compute pipeline. Commands + cron times live in SeoCommandRegistry —
// the SAME source the admin SEO Manage page reads for its trigger whitelist
// and next-run display, so the two can't drift. Each scheduled run records a
// seo_command_runs row (via the hooks) sharing one history with manual admin
// triggers; ->skip() defers the nightly run while a manual run is still in
// flight (RunSeoCommand's guards cover the opposite direction). The recorder
// is fully defensive — a bookkeeping failure never breaks the pipeline.
// Verify after ANY change here: `php artisan schedule:list`.
foreach (SeoCommandRegistry::scheduled() as $command => $meta) {
    Schedule::command($command)
        ->cron($meta['cron'])
        ->withoutOverlapping()
        ->skip(fn () => SeoCommandRunRecorder::hasActiveRun($command))
        ->before(fn () => SeoCommandRunRecorder::startScheduled($command))
        ->onSuccess(fn () => SeoCommandRunRecorder::finishScheduled($command, true))
        ->onFailure(fn () => SeoCommandRunRecorder::finishScheduled($command, false));
}
// Automated YouTube listing videos — inert until YOUTUBE_UPLOADS_ENABLED=true
// (the command exits immediately when disabled). Half-hourly pacing + the
// daily_upload_cap config keep renders light on the box and inside quota.
Schedule::command('youtube:process-uploads')->everyThirtyMinutes()->withoutOverlapping();

// ── NATCON 2026 photo-collection campaign ───────────────────────────────────
//
// Sending is a cron drain, not a queue: production has no `queue:work` worker
// (see the comment in app/Mail/MessageNotificationMailer.php, where a previous
// ShouldQueue mailer silently dropped every message into the `jobs` table).
// The admin's Send button only writes natcon_outbox rows; this drains them.
//
// Inert until NATCON_SEND_MODE is flipped off `off` — the drain reports what it
// would send and stops.
//
// ⚠️ config('app.timezone') is UTC and no other entry in this file sets a
//    timezone. ->timezone('Asia/Manila') here is deliberate: without it the
//    reminder pass fires at 17:00 Manila, which is the worst send hour of the
//    day and the highest complaint risk.
//    Verify after ANY change here: `php artisan schedule:list` (it prints UTC).
Schedule::command('natcon:drain-outbox')
    ->everyMinute()
    ->withoutOverlapping();

// The live stream's YouTube chat, stored while the admin's Live switch is on (the live-page raffle's
// commenter list). Each run reads for ~50s; no-ops when nothing is live or no YouTube credential is set.
Schedule::command('natcon:collect-live-chat')
    ->everyMinute()
    ->withoutOverlapping();

// Self-checks against natcon_events.reminder_offsets vs photo_deadline_at and
// no-ops on any other day, so this is safe to leave scheduled forever. Default
// offsets [4,3,2] against the Aug 24 2026 deadline == Aug 20 / 21 / 22. Moving
// the deadline in the admin moves the reminders with it — no deploy.
Schedule::command('natcon:queue-reminders')
    ->dailyAt('09:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// Backfills Leuterio Realty awardee data for recipients still pending or errored.
// Import deliberately doesn't call LR inline (they rate-limit to 60 req/min from
// a single IP), so this drains the backlog and heals a transient LR outage
// without anyone having to notice.
Schedule::command('natcon:hydrate-awardees --limit=200')
    ->hourly()
    ->withoutOverlapping();

// Retry sweep for gallery photos whose Rekognition indexing failed at upload
// time (uploads index inline — no queue worker in production) — and the
// backfill for photos uploaded before the face columns existed
// (faces_indexed_at NULL is the whole work-list). No-ops when everything is
// indexed, so it is safe to leave scheduled forever.
Schedule::command('natcon:index-gallery-faces --limit=25')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// The 45-day agent dormancy rule. Deliberately scheduled WITHOUT --force, so
// this is a daily DRY RUN: it writes nothing and only logs how many agents the
// rule would deactivate.
//
// The rule used to be evaluated at sign-in, where it could only ever catch
// agents on their way back (see User::recordLoginActivity). Nobody absent has
// ever been swept, so the first enforcing run carries a backlog reaching to
// the policy start — and deactivating an agent pulls their listings from the
// public site, the directory and the sitemap. Enforcing that blind could be a
// site-wide SEO event, so the number goes in the log first and someone decides.
//
// To enforce, run it by hand in batches — `agents:deactivate-dormant --force
// --limit=50` — and only add --force here once the steady-state daily figure
// is small and understood.
Schedule::command('agents:deactivate-dormant')
    ->dailyAt('02:30')
    ->timezone('Asia/Manila')
    ->withoutOverlapping(10);
