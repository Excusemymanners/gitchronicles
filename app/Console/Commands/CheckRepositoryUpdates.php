<?php

namespace App\Console\Commands;

use App\Actions\UpdateRepository;
use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Git\RemoteHead;
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
 */
#[Signature('repositories:check-updates {repository? : Check only the repository with this id}')]
#[Description('Start an incremental update for the ready repositories that have new commits on GitHub')]
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
                $this->warn("{$name}: GitHub nu a răspuns, încerc data viitoare.");

                continue;
            }

            if ($remoteSha === $repository->last_sha) {
                $this->line("{$name}: la zi (commitul ".substr($remoteSha, 0, 7).').');

                continue;
            }

            if (! $updateRepository->handle($repository, ProcessingTrigger::Schedule)) {
                $this->line("{$name}: commit nou (".substr($remoteSha, 0, 7).'), dar ultima actualizare a pornit de curând; îl iau la verificarea următoare.');

                continue;
            }

            $this->info("{$name}: commit nou (".substr($remoteSha, 0, 7).'), am pornit actualizarea.');
            $started++;
        }

        $this->line("Verificate: {$repositories->count()}. Actualizări pornite: {$started}.");

        return self::SUCCESS;
    }
}
