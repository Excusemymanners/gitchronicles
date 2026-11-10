<?php

namespace App\Jobs;

use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Exceptions\RepositoryProcessingException;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The entry point of the processing pipeline: it records a new processing run and queues
 * the steps as a chain, so each one starts only after the previous one succeeded:
 *
 *     CloneRepository → AnalyzeRepository → FinishProcessing (status "ready")
 *
 * The next phases add their steps before FinishProcessing (documentation, diagrams, search).
 * If any step fails, the chain stops and its "catch" marks the repository and the run as failed.
 *
 * Only one pipeline per repository: ShouldBeUnique keeps a second one from being queued while
 * this job waits in the queue, and handle() does nothing while another run is still running.
 */
class ProcessRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Only one attempt: if starting the chain fails, the repository stays "pending" without a running
     * run, and `php artisan repositories:process` can start it again.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run.
     */
    public int $timeout = 30;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * How long (in seconds) the uniqueness lock lasts if the job is never processed.
     */
    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Repository $repository,
        public ProcessingTrigger $trigger,
    ) {}

    /**
     * The key of the uniqueness lock: one queued pipeline per repository.
     */
    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    /**
     * Record the run and queue the chain of steps.
     */
    public function handle(): void
    {
        if ($this->repository->isBeingProcessed()) {
            Log::info('Processing skipped: another run for this repository is still running.', [
                'repository_id' => $this->repository->id,
            ]);

            return;
        }

        $repository = $this->repository;

        $run = $repository->processingRuns()->create([
            'mode' => ProcessingMode::Initial,
            'trigger' => $this->trigger,
            'status' => ProcessingRunStatus::Running,
            'started_at' => now(),
        ]);

        Bus::chain([
            new CloneRepository($repository, $run),
            new AnalyzeRepository($repository, $run),
            new FinishProcessing($repository, $run),
        ])->catch(function (Throwable $exception) use ($repository, $run): void {
            $message = RepositoryProcessingException::messageFor($exception);

            $repository->update(['status' => RepositoryStatus::Failed, 'error' => $message]);
            $run->finish(ProcessingRunStatus::Failed, $message);
        })->dispatch();
    }
}
