<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduled check for new commits (Faza 9): `repositories:check-updates` asks GitHub for the newest
// commit of every ready repository and starts an incremental update where it changed. When it runs is
// a cron expression in config (UPDATES_CHECK_CRON, every 15 minutes by default); empty turns it off.
// Locally the scheduler itself must be running: php artisan schedule:work.
if (filled(config('gitchronicle.incremental.check_cron'))) {
    Schedule::command('repositories:check-updates')
        ->cron(config('gitchronicle.incremental.check_cron'))
        ->withoutOverlapping();
}
