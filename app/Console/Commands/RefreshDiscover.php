<?php

namespace App\Console\Commands;

use App\Discover\StarCollector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Collects today's stars for the „Descoperă” leaderboard (bonus):
 *
 *     php artisan discover:refresh
 *
 * The Laravel Scheduler runs it once a day (routes/console.php, `php artisan schedule:work` locally).
 * It ends with one log line and exits with a non-zero code when GitHub stopped it early (rate limit,
 * no network, an error), so a failed daily run is visible; what was collected until then is kept.
 */
#[Signature('discover:refresh')]
#[Description('Collect today\'s GitHub stars of interesting repositories for the „Descoperă” leaderboard')]
class RefreshDiscover extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StarCollector $collector): int
    {
        $startedAt = microtime(true);

        $this->line('Caut repository-uri interesante cu GitHub Search API (cu pauze între căutări, ca să respectăm limita)…');

        $summary = $collector->collect(fn (string $line) => $this->line("  {$line}"));

        $seconds = round(microtime(true) - $startedAt, 1);
        $context = [...$summary->toArray(), 'seconds' => $seconds];
        $numbers = "{$summary->requests} cereri către GitHub, {$summary->repositoriesSeen} repository-uri văzute azi "
            ."({$summary->newRepositories} noi), {$summary->snapshotsToday} notări pentru {$summary->day}, în {$seconds} s.";

        if ($summary->failed()) {
            Log::warning('discover:refresh stopped early.', $context);
            $this->error("Oprit înainte de final: {$summary->failure} Am păstrat ce am adunat până atunci.");
            $this->line($numbers);

            return self::FAILURE;
        }

        Log::info('discover:refresh finished.', $context);
        $this->info("Gata: {$numbers}");

        if ($summary->notRefreshed > 0) {
            $this->line("{$summary->notRefreshed} repository-uri urmărite au rămas fără notare azi (limita zilnică de cereri); intră mâine primele la rând.");
        }

        return self::SUCCESS;
    }
}
