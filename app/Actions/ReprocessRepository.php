<?php

namespace App\Actions;

use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Jobs\ProcessRepository;
use App\Models\Repository;

/**
 * Starts the processing pipeline again, by hand: the „Reîncearcă” button
 * (RepositoryController::retry) and the `repositories:process` command use it.
 */
final class ReprocessRepository
{
    /**
     * Put the repository back in the queue: "pending" right away (so the page shows it),
     * the old error removed, and a new pipeline run with the "manual" trigger.
     *
     * A repository that was already processed to the end gets an incremental update (Faza 9);
     * $full asks for everything to be processed again instead.
     */
    public function handle(Repository $repository, bool $full = false): void
    {
        $repository->update([
            'status' => RepositoryStatus::Pending,
            'error' => null,
        ]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual, $full);
    }
}
