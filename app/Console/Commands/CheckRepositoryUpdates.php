<?php

namespace App\Console\Commands;

use App\Actions\UpdateRepository;
use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Git\RemoteHead;
use App\Incremental\RepositoryCompleteness;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Looks for new commits on GitHub and starts an incremental update where there are some (Faza 9).
 * The scheduler runs it by itself (routes/console.php), for the repositories without a webhook.
 *
 *     php artisan repositories:check-updates
 *     php artisan repositories:check-updates 5
 *
 * For every ready repository it asks GitHub for the newest commit (`git ls-remote`, no clone and no
 * fetch) and compares it with the last processed one (last_sha). Only a different commit starts the
 * pipeline, so a repository without news costs one small request and leaves no run behind.
 * Repositories that are being processed or have failed are left alone: the first are already
 * working, the others wait for someone to press „Reîncearcă”. A repository whose last update started
 * less than the minimum interval ago waits for a later check (see UpdateRepository).
 *
 * Self-healing: an incomplete repository (RepositoryCompleteness) gets a full processing even when
 * GitHub has no new commit, so the artifacts an older pipeline did not make appear by themselves.
 * At an unchanged commit this happens only once (RepositoryCompleteness::healsAutomatically()).
 */
#[Signature('repositories:check-updates {repository? : Check only the repository with this id}')]
#[Description('Start an update for the ready repositories that have new commits on GitHub, or a full processing for the incomplete ones')]
class CheckRepositoryUpdates extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(UpdateRepository $updateRepository): int
    {
        $repositories = Repository::query()
            ->where('status', RepositoryStatus::Ready)
            ->when($this->argument('repository') !== null, fn ($query) => $query->whereKey($this->argument('repository')))
            ->orderBy('id')
            ->get();

        $started = 0;

        foreach ($repositories as $repository) {
            $name = "#{$repository->id} {$repository->owner}/{$repository->name}";
            $remoteSha = RemoteHead::of($repository->url);

            if ($remoteSha === null) {
                $this->warn("{$name}: GitHub did not answer, trying again next time.");

                continue;
            }

            $shortSha = substr($remoteSha, 0, 7);
            $completeness = RepositoryCompleteness::of($repository);
            $isHealing = $remoteSha === $repository->last_sha;

            if ($isHealing && ! $completeness->healsAutomatically()) {
                $this->line($completeness->isIncomplete()
                    ? "{$name}: up to date (commit {$shortSha}), but missing {$completeness->missingText()}; a full processing at this commit did not make them, so it is not repeated."
                    : "{$name}: up to date (commit {$shortSha}).");

                continue;
            }

            $reason = $isHealing ? "missing {$completeness->missingText()}" : "new commit ({$shortSha})";

            if (! $updateRepository->handle($repository, ProcessingTrigger::Schedule)) {
                $this->line("{$name}: {$reason}, but the last update started recently; it will be checked again next time.");

                continue;
            }

            $this->info($completeness->isIncomplete()
                ? "{$name}: {$reason}, started a full processing."
                : "{$name}: {$reason}, started the update.");
            $started++;
        }

        $this->line("Checked: {$repositories->count()}. Updates started: {$started}.");

        return self::SUCCESS;
    }
}
