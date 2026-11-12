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
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The entry point of the processing pipeline: it records a new processing run and queues
 * the steps listed in STAGES as a chain, so each one starts only after the previous one succeeded.
 * If any step fails, the chain stops and its "catch" marks the repository and the run as failed.
 *
 * Only one pipeline per repository: ShouldBeUnique keeps a second one from being queued while
 * this job waits in the queue, and handle() does nothing while another run is still running.
 *
 * The kind of run (Faza 9) is decided here:
 *
 * - Initial: the repository was never processed to the end, so everything is done from zero;
 * - Incremental: it was, so the run starts from the commit of the last successful run (from_sha)
 *   and each step handles only what changed since then. AnalyzeRepository turns the run into a
 *   Full one when too much changed;
 * - Full: asked for explicitly ($full): every file and every page is looked at again.
 *
 * from_sha is the commit of the last run that succeeded, not the commit of the clone: after a failed
 * run the next one starts again from the last commit that was processed to the end.
 */
class ProcessRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The steps of the pipeline, in the order they run. The full order, filled in phase by phase:
     *
     *     CloneRepository → AnalyzeRepository → ImportCommits → GenerateDocumentation
     *         → BuildDiagrams → IndexCode → FinishProcessing (status "ready")
     *
     * Every step is a queued job built as `new Step($repository, $run)`, so a phase adds its step
     * by uncommenting (or writing) one line in its place below.
     *
     * @var list<class-string<ShouldQueue>>
     */
    public const STAGES = [
        CloneRepository::class,

        AnalyzeRepository::class,

        ImportCommits::class,

        GenerateDocumentation::class,

        BuildDiagrams::class,

        IndexCode::class,

        FinishProcessing::class,
    ];

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
        public bool $full = false,
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
        $lastSuccessfulRun = $repository->lastSuccessfulRun();

        $run = $repository->processingRuns()->create([
            'mode' => match (true) {
                $lastSuccessfulRun === null => ProcessingMode::Initial,
                $this->full => ProcessingMode::Full,
                default => ProcessingMode::Incremental,
            },
            'trigger' => $this->trigger,
            'from_sha' => $lastSuccessfulRun?->to_sha,
            'status' => ProcessingRunStatus::Running,
            'started_at' => now(),
        ]);

        // An update keeps one status for all its steps: the documentation is already on the page.
        if ($run->mode !== ProcessingMode::Initial) {
            $repository->update(['status' => RepositoryStatus::Updating, 'error' => null]);
        }

        $steps = array_map(fn (string $step): ShouldQueue => new $step($repository, $run), self::STAGES);

        Bus::chain($steps)->catch(function (Throwable $exception) use ($repository, $run): void {
            $message = RepositoryProcessingException::messageFor($exception);

            $repository->update(['status' => RepositoryStatus::Failed, 'error' => $message]);
            $run->finish(ProcessingRunStatus::Failed, $message);

            // A repository that never finished a run has nothing on the page that needs its files: the clone
            // would only take disk space until somebody retries. (An earlier success keeps its clone for the update.)
            if ($repository->lastSuccessfulRun() === null) {
                Storage::disk('local')->deleteDirectory($repository->cloneDirectory());
            }
        })->dispatch();
    }
}
