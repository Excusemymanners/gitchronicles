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

// „Descoperă” (bonus): once a day, write down the stars of the watched repositories. The hour and the
// switch come from config/gitchronicle.php (DISCOVER_SCHEDULE_TIME, DISCOVER_SCHEDULE_ENABLED); the switch
// is checked at run time. Locally the scheduler runs with `php artisan schedule:work`.
Schedule::command('discover:refresh')
    ->dailyAt(config('gitchronicle.discover.schedule_time'))
    ->when(fn (): bool => (bool) config('gitchronicle.discover.schedule_enabled'))
    ->withoutOverlapping();

// Fresh data for the „Consultanță” reports (config gitchronicle.consulting):
// - weekly, on Monday night, the community Semgrep rules are refreshed (scanners/update-rules.sh keeps the
//   previous copy until the new one validates and writes the date and revisions the page shows);
// - every day the reports older than stale_after_days (7) are made again, so a report is never much older
//   than a week: new vulnerabilities are published every day. It runs after the rules refresh.
Schedule::exec('bash scanners/update-rules.sh')
    ->weeklyOn(1, '03:30')
    ->when(fn (): bool => (bool) config('gitchronicle.consulting.rules_update_schedule'))
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('repositories:consult --stale')
    ->dailyAt(config('gitchronicle.consulting.stale_schedule_time'))
    ->withoutOverlapping();
