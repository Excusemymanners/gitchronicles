<?php

namespace App\Jobs;

use App\Enums\ProcessingRunStatus;
use App\Enums\RepositoryStatus;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The last step of the pipeline: every step before it succeeded, so the repository is ready
 * and the run is closed as succeeded, with its duration.
 */
class FinishProcessing implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job may run.
     */
    public int $timeout = 30;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Repository $repository,
        public ProcessingRun $run,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->repository->update(['status' => RepositoryStatus::Ready]);

        $this->run->finish(ProcessingRunStatus::Succeeded);
    }
}
