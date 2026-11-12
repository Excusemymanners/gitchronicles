<?php

namespace App\Console\Commands;

use App\Discover\StarCollector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Collects today's stars for the Discover leaderboard (bonus):
 *
 *     php artisan discover:refresh
 *
 * The Laravel Scheduler runs it once a day (routes/console.php, `php artisan schedule:work` locally).
 * It ends with one log line and exits with a non-zero code when GitHub stopped it early (rate limit,
 * no network, an error), so a failed daily run is visible; what was collected until then is kept.
 */
#[Signature('discover:refresh')]
#[Description('Collect today\'s GitHub stars of interesting repositories for the Discover leaderboard')]
class RefreshDiscover extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StarCollector $collector): int
    {
        $startedAt = microtime(true);

        $this->line('Searching for interesting repositories with the GitHub Search API (pausing between searches to respect the limit)…');

        $summary = $collector->collect(fn (string $line) => $this->line("  {$line}"));

        $seconds = round(microtime(true) - $startedAt, 1);
        $context = [...$summary->toArray(), 'seconds' => $seconds];
        $numbers = "{$summary->requests} GitHub requests, {$summary->repositoriesSeen} ".Str::plural('repository', $summary->repositoriesSeen).' seen today '
            ."({$summary->newRepositories} new), {$summary->snapshotsToday} star counts recorded for {$summary->day}, in {$seconds} s.";

        if ($summary->failed()) {
            Log::warning('discover:refresh stopped early.', $context);
            $this->error("Stopped before the end: {$summary->failure} Kept what was collected until then.");
            $this->line($numbers);

            return self::FAILURE;
        }

        Log::info('discover:refresh finished.', $context);
        $this->info("Done: {$numbers}");

        if ($summary->notRefreshed > 0) {
            $this->line("{$summary->notRefreshed} tracked ".Str::plural('repository', $summary->notRefreshed).' got no star count today (daily request limit); they go first tomorrow.');
        }

        return self::SUCCESS;
    }
}
