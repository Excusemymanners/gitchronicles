<?php

namespace App\Actions;

use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Jobs\ProcessRepository;
use App\Models\Repository;

/**
 * Starts an update of a repository that is already processed (Faza 9): the pipeline fetches the new
 * commits and redoes only what they changed. Three things start it, each with its own trigger:
 * the „Verifică actualizări” button (Manual), a push announced by GitHub's webhook (Webhook) and
 * the scheduled check (Schedule).
 *
 * The two automatic triggers respect a minimum interval per repository
 * (config('gitchronicle.incremental.min_interval_minutes')): an update that may call Claude starts at
 * most once in that interval, however many pushes arrive. A commit that comes in between is not lost:
 * the next scheduled check (or the next push after the interval) sees it is newer than last_sha.
 * The button is not limited here: it needs a logged-in user and has its own rate limit (the route's throttle).
 */
final class UpdateRepository
{
    /**
     * Put the repository in the queue for an update and show it as "updating" right away,
     * so its page starts following the run. ProcessRepository lets only one run work at a time.
     *
     * Returns false, and starts nothing, when an automatic trigger comes too soon after the last run.
     */
    public function handle(Repository $repository, ProcessingTrigger $trigger): bool
    {
        if ($trigger->isAutomatic() && $this->ranRecently($repository)) {
            return false;
        }

        $repository->update([
            'status' => RepositoryStatus::Updating,
            'error' => null,
        ]);

        ProcessRepository::dispatch($repository, $trigger);

        return true;
    }

    /**
     * Whether the repository's newest run that did some work started less than the minimum interval ago.
     * A run that found nothing new (Skipped) does not count: it cost nothing.
     */
    private function ranRecently(Repository $repository): bool
    {
        $minutes = (int) config('gitchronicle.incremental.min_interval_minutes');

        if ($minutes <= 0) {
            return false;
        }

        $lastStartedAt = $repository->processingRuns()
            ->where('status', '!=', ProcessingRunStatus::Skipped)
            ->latest('id')
            ->value('started_at');

        return $lastStartedAt !== null && now()->subMinutes($minutes)->lessThan($lastStartedAt);
    }
}
