<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('listings:expire-featured')->daily();
Schedule::command('ats:expiry-reminders')->dailyAt('00:00')->withoutOverlapping();
Schedule::command('agents:deactivate-dormant')->dailyAt('00:00')->withoutOverlapping();
Schedule::command('agents:recompute-response-metrics')->hourly()->withoutOverlapping();
Schedule::command('seo:compute-modifier-thresholds')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('seo:compute-facility-counts')->dailyAt('04:00')->withoutOverlapping();
