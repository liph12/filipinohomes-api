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

// Nightly boss digest — today's site activity to every un-muted admin, at the
// close of the Manila day. `php artisan reports:send-activity you@x.com` for a
// one-off test send.
Schedule::command('reports:send-activity')
    ->dailyAt('23:59')
    ->timezone('Asia/Manila')
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
