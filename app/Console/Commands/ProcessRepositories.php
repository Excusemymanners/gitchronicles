<?php

namespace App\Console\Commands;

use App\Actions\ReprocessRepository;
use App\Enums\RepositoryStatus;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Starts the processing pipeline by hand, for one repository or for every pending one.
 * Useful for the repositories added before the pipeline existed, and after a failure.
 *
 *     php artisan repositories:process 5
 *     php artisan repositories:process --all-pending
 *
 * The command only puts the jobs in the queue: a worker runs them (php artisan queue:work).
 */
#[Signature('repositories:process
    {repository? : The id of the repository to process}
    {--all-pending : Process every repository that is still pending}')]
#[Description('Start the processing pipeline (clone, ...) for one repository or for all pending ones')]
class ProcessRepositories extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReprocessRepository $reprocessRepository): int
    {
        $repositoryId = $this->argument('repository');
        $allPending = (bool) $this->option('all-pending');

        $hasNeither = $repositoryId === null && ! $allPending;
        $hasBoth = $repositoryId !== null && $allPending;

        if ($hasNeither || $hasBoth) {
            $this->error('Dă fie id-ul unui repository (repositories:process 5), fie --all-pending.');

            return self::FAILURE;
        }

        if ($allPending) {
            $repositories = Repository::query()
                ->where('status', RepositoryStatus::Pending)
                ->orderBy('id')
                ->get();
        } else {
            $repositories = Repository::query()->whereKey($repositoryId)->get();

            if ($repositories->isEmpty()) {
                $this->error("Nu există niciun repository cu id-ul {$repositoryId}.");

                return self::FAILURE;
            }
        }

        $startedCount = 0;

        foreach ($repositories as $repository) {
            $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

            if ($repository->isBeingProcessed()) {
                $this->warn("{$name} se procesează chiar acum, îl las în pace.");

                continue;
            }

            $reprocessRepository->handle($repository);
            $this->info("{$name}: procesarea a pornit.");
            $startedCount++;
        }

        $this->line("Am trimis {$startedCount} repository-uri în coadă. Le procesează un worker: php artisan queue:work");

        return self::SUCCESS;
    }
}
