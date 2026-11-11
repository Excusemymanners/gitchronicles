<?php

namespace App\Incremental;

use App\Enums\ProcessingRunStatus;
use App\Models\Commit;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Support\Collection;

/**
 * The commits of a repository that GitChronicle processed to the end, in the order it processed
 * them: the stops of the time travel (Faza 9). Each stop is the last successful run of its commit,
 * so a commit processed again (a full reprocessing, a retry of stale pages) shows its newest state.
 *
 * How an old version is found: every version of a page or of a diagram remembers the run that wrote
 * it (processing_run_id). Run ids only grow, so the repository as it was right after the run of a stop
 * is, for each page and diagram, its newest version written by a run with an id up to that run's id.
 * A page that did not change for five commits is simply its last version before the chosen commit, and
 * the pages written by a run that failed later count too: they stayed on the site.
 */
final class ProcessedCommits
{
    /**
     * The processed commits, oldest first, each with what the commit list knows about it.
     * The last one is the current state of the repository.
     *
     * @return list<array{sha: string, processed_at: string|null, subject: string|null, authored_at: string|null, is_current: bool}>
     */
    public function of(Repository $repository): array
    {
        $stops = $this->stops($repository);
        $commits = Commit::whereBelongsTo($repository)->whereIn('sha', $stops->keys())->get(['sha', 'subject', 'authored_at'])->keyBy('sha');
        $currentSha = $stops->keys()->last();

        return $stops->map(fn (ProcessingRun $run, string $sha): array => [
            'sha' => $sha,
            'processed_at' => $run->finished_at?->toIso8601String(),
            'subject' => $commits->get($sha)?->subject,
            'authored_at' => $commits->get($sha)?->authored_at?->toIso8601String(),
            'is_current' => $sha === $currentSha,
        ])->values()->all();
    }

    /**
     * The run of a processed commit (its last successful run): the versions up to it are the repository
     * as it was at that commit. Null when the commit was never processed to the end.
     */
    public function runAt(Repository $repository, string $sha): ?ProcessingRun
    {
        return $this->stops($repository)->get($sha);
    }

    /**
     * The newest processed commit, or null when the repository was never processed to the end.
     */
    public function current(Repository $repository): ?string
    {
        return $this->stops($repository)->keys()->last();
    }

    /**
     * The last successful run of every processed commit, by sha, in the order of those runs.
     * (keyBy keeps the last run of each sha; sortBy puts the stops in the order of their runs.)
     *
     * @return Collection<string, ProcessingRun>
     */
    private function stops(Repository $repository): Collection
    {
        return $repository->processingRuns()
            ->where('status', ProcessingRunStatus::Succeeded)
            ->whereNotNull('to_sha')
            ->orderBy('id')
            ->get(['id', 'to_sha', 'finished_at', 'module_paths'])
            ->keyBy('to_sha')
            ->sortBy('id');
    }
}
