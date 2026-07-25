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
