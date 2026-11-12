<?php

namespace App\Jobs;

use App\Consulting\ConsultingScanner;
use App\Enums\ConsultingStatus;
use App\Models\ConsultingReport;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Makes the „Consultanță” report of a repository at its current commit (ConsultingScanner). Like the video,
 * it is not a step of the processing pipeline: FinishProcessing queues it after the repository is already
 * "ready", on the "media" queue (config gitchronicle.consulting.queue), so a slow scan never delays "ready".
 *
 * queueFor() is the way to start it (a method named queue() would replace the one Laravel uses to push the job) (FinishProcessing, `repositories:consult`, „Rulează analiza”): it marks the
 * report of the current commit as pending first, so the page shows „În așteptare” at once. A commit that
 * already has a report is not analyzed again unless $force.
 */
class GenerateConsultingReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One attempt: a tool that failed once would fail the same way again.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: the three tools with their own timeouts (scc 60,
     * OSV-Scanner 120, Semgrep 300, see config) and one AI call for the summary (at most 300).
     */
    public int $timeout = 900;

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
        public bool $force = false,
    ) {
        $this->onQueue(config('gitchronicle.consulting.queue'));
    }

    /**
     * Mark the report of the repository's current commit as pending and queue the analysis.
     * Nothing happens when that commit already has a report (ready, or waiting, or running), unless $force.
     */
    public static function queueFor(Repository $repository, bool $force = false): void
    {
        $report = $repository->consultingReports()->firstOrNew(['source_sha' => $repository->last_sha]);

        if (! $force && $report->exists && $report->status !== ConsultingStatus::Failed) {
            return;
        }

        $report->fill(['status' => ConsultingStatus::Pending, 'error' => null])->save();

        self::dispatch($repository, $force);
    }

    /**
     * The key of the uniqueness lock: one queued analysis per repository.
     */
    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    /**
     * Execute the job. The report is made here when queueFor() was skipped (a job dispatched directly).
     */
    public function handle(ConsultingScanner $scanner): void
    {
        $report = $this->repository->consultingReports()->firstOrNew(['source_sha' => $this->repository->last_sha]);

        if (! $this->force && $report->status === ConsultingStatus::Ready) {
            return;
        }

        $scanner->scan($report);
    }

    /**
     * Whatever went wrong, the page must not stay on „În așteptare” or „Se analizează”.
     */
    public function failed(?Throwable $exception): void
    {
        $this->repository->consultingReports()
            ->whereIn('status', [ConsultingStatus::Pending, ConsultingStatus::Running])
            ->get()
            ->each(fn (ConsultingReport $report) => $report->markFailed(ConsultingReport::ERROR_UNEXPECTED));
    }
}
