<?php

namespace App\Console\Commands;

use App\Actions\ReprocessRepository;
use App\Enums\RepositoryStatus;
use App\Incremental\RepositoryCompleteness;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Starts the processing pipeline by hand, for one repository, for every pending one or for every
 * incomplete one. Useful for the repositories added before the pipeline existed, and after a failure.
 *
 *     php artisan repositories:process 5
 *     php artisan repositories:process 5 --full
 *     php artisan repositories:process --all-pending
 *     php artisan repositories:process --all-incomplete
 *
 * A repository that was already processed gets an incremental update: only what changed since its
 * last processed commit. --full processes everything again (every file parsed, every page checked).
 * --all-incomplete processes in full every ready repository that misses an artifact of the pipeline
 * (RepositoryCompleteness), for example one processed by an older version of the pipeline.
 *
 * The command only puts the jobs in the queue: a worker runs them (php artisan queue:work).
 */
#[Signature('repositories:process
    {repository? : The id of the repository to process}
    {--all-pending : Process every repository that is still pending}
    {--all-incomplete : Process in full every ready repository that misses an artifact (documentation, diagrams, ...)}
    {--full : Process everything again instead of only what changed}')]
#[Description('Start the processing pipeline (clone, ...) for one repository, for all pending ones or for all incomplete ones')]
class ProcessRepositories extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReprocessRepository $reprocessRepository): int
    {
        $repositoryId = $this->argument('repository');
        $allPending = (bool) $this->option('all-pending');
        $allIncomplete = (bool) $this->option('all-incomplete');

        // Exactly one of the three ways to choose the repositories.
        if (count(array_filter([$repositoryId !== null, $allPending, $allIncomplete])) !== 1) {
            $this->error('Give either a repository id (repositories:process 5), or --all-pending, or --all-incomplete.');

            return self::FAILURE;
        }

        if ($allIncomplete) {
            return $this->processIncomplete($reprocessRepository);
        }

        if ($allPending) {
            $repositories = Repository::query()
                ->where('status', RepositoryStatus::Pending)
                ->orderBy('id')
                ->get();
        } else {
            $repositories = Repository::query()->whereKey($repositoryId)->get();

            if ($repositories->isEmpty()) {
                $this->error("There is no repository with id {$repositoryId}.");

                return self::FAILURE;
            }
        }

        $startedCount = 0;

        foreach ($repositories as $repository) {
            $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

            if ($repository->isBeingProcessed()) {
                $this->warn("{$name} is being processed right now, leaving it alone.");

                continue;
            }

            $reprocessRepository->handle($repository, (bool) $this->option('full'));
            $this->info("{$name}: procesarea a pornit.");
            $startedCount++;
        }

        $this->line("Queued {$startedCount} ".Str::plural('repository', $startedCount).'. A worker processes them: php artisan queue:work');

        return self::SUCCESS;
    }

    /**
     * --all-incomplete: a full processing for every ready repository that misses an artifact of the pipeline.
     * Only ready ones: a failed repository waits for „Reîncearcă”, a pending one for --all-pending.
     */
    private function processIncomplete(ReprocessRepository $reprocessRepository): int
    {
        $startedCount = 0;

        foreach (Repository::query()->where('status', RepositoryStatus::Ready)->orderBy('id')->get() as $repository) {
            $completeness = RepositoryCompleteness::of($repository);

            if (! $completeness->isIncomplete() || $repository->isBeingProcessed()) {
                continue;
            }

            $reprocessRepository->handle($repository, full: true);
            $this->info("#{$repository->id} {$repository->owner}/{$repository->name}: missing {$completeness->missingText()}, the full processing started.");
            $startedCount++;
        }

        $this->line($startedCount === 0
            ? 'No ready repository has missing chapters.'
            : "Queued {$startedCount} ".Str::plural('repository', $startedCount).'. A worker processes them: php artisan queue:work');

        return self::SUCCESS;
    }
}
