<?php

namespace App\Jobs;

use App\Analysis\RepositoryAnalyzer;
use App\Enums\ProcessingMode;
use App\Enums\RepositoryStatus;
use App\Git\ChangeSet;
use App\Incremental\ImpactAnalysis;
use App\Incremental\ModuleGraph;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Step 2 of the pipeline: analyze the code of the clone with tree-sitter (RepositoryAnalyzer)
 * and record on the run how much work it did: files_total (every file git tracks),
 * files_analyzed (the files tree-sitter really parsed in this run) and modules_total.
 *
 * For an update (Faza 9) this step also finds out what the next steps have to redo:
 *
 * 1. the change set: `git diff --name-status` between the last processed commit and the new one;
 * 2. the threshold: when more than config('gitchronicle.incremental.full_threshold') of the tracked
 *    files changed (40%), or the two commits cannot be compared, the run becomes a Full one:
 *    working out what is affected would cost more than doing everything again;
 * 3. the analysis itself: every file is part of it, but only the changed ones are parsed again
 *    (the analyzer's cache); a Full run parses everything;
 * 4. the impact: the modules with changed files plus their neighbours in the dependency graph
 *    (ImpactAnalysis), compared on the graph before and after the analysis.
 *
 * The result is saved on the run (files_changed, modules_affected, "change_summary", "module_paths"):
 * the documentation and index steps read it from there, and the „Istoric” tab shows it.
 *
 * The job is idempotent: every analysis brings the saved one in line with the clone.
 */
class AnalyzeRepository implements ShouldQueue
{
    use Queueable;

    /**
     * How many changed files of the root folder are remembered by name on the run.
     */
    private const MAX_LISTED_PROJECT_FILES = 20;

    /**
     * One attempt: the analysis does not use the network, so an error would happen again.
     * After a failure the user can press „Reîncearcă”.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: the analyzer plus a margin for saving.
     */
    public int $timeout = 300;

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
    public function handle(RepositoryAnalyzer $analyzer): void
    {
        $this->repository->update(['status' => $this->run->statusFor(RepositoryStatus::Analyzing)]);

        $changes = $this->changesSinceLastRun();
        $fullReason = $this->reasonToProcessEverything($changes);

        if ($fullReason !== null) {
            $this->run->update(['mode' => ProcessingMode::Full]);
        }

        $before = ModuleGraph::of($this->repository);
        $summary = $analyzer->analyze($this->repository, fromScratch: ! $this->run->isIncremental());
        $after = ModuleGraph::of($this->repository);

        $impact = $changes === null ? null : ImpactAnalysis::of($changes, $before, $after);

        $this->run->update([
            'files_total' => $summary['files_total'],
            'files_analyzed' => $summary['files_parsed'],
            'modules_total' => $summary['modules_total'],
            'module_paths' => $after->modulePaths,
            'files_changed' => $changes?->count() ?? 0,
            // A Full run looks at every module again; an Initial one has nothing to compare with.
            'modules_affected' => match ($this->run->mode) {
                ProcessingMode::Incremental => count($impact['modules']),
                ProcessingMode::Full => $summary['modules_total'],
                ProcessingMode::Initial => 0,
            },
            'change_summary' => $changes === null ? ($fullReason === null ? null : ['full_reason' => $fullReason]) : [
                'added' => count($changes->added),
                'modified' => count($changes->modified),
                'deleted' => count($changes->deleted),
                'modules' => $this->run->isIncremental() ? $impact['modules'] : [],
                'modules_added' => $impact['modules_added'],
                'modules_removed' => $impact['modules_removed'],
                'project_files' => $this->projectFiles($changes),
                'full_reason' => $fullReason,
            ],
        ]);
    }

    /**
     * The files changed between the last processed commit and the clone's commit, or null when
     * there is no earlier commit (the first processing) or git cannot compare the two.
     */
    private function changesSinceLastRun(): ?ChangeSet
    {
        if ($this->run->from_sha === null || $this->run->to_sha === null) {
            return null;
        }

        return ChangeSet::between($this->repository->clonePath(), $this->run->from_sha, $this->run->to_sha);
    }

    /**
     * Why an incremental run must process everything after all, in Romanian for the „Istoric” tab,
     * or null when it can stay incremental (and for the runs that are not incremental anyway).
     */
    private function reasonToProcessEverything(?ChangeSet $changes): ?string
    {
        if (! $this->run->isIncremental()) {
            return null;
        }

        if ($changes === null) {
            return 'Commitul procesat ultima dată nu mai există în istoricul repository-ului, deci nu avem cu ce compara.';
        }

        $threshold = (float) config('gitchronicle.incremental.full_threshold');
        $trackedFiles = max(1, $this->run->files_total);

        if ($changes->count() > $threshold * $trackedFiles) {
            $percent = round($changes->count() / $trackedFiles * 100);
            $limit = round($threshold * 100);

            return "S-au schimbat {$changes->count()} din {$trackedFiles} fișiere ({$percent}%), peste pragul de {$limit}%.";
        }

        return null;
    }

    /**
     * The changed files that sit in the root folder (README, composer.json, package.json, ...):
     * they describe the whole project, so a change in them refreshes the overview and the README.
     *
     * @return list<string>
     */
    private function projectFiles(ChangeSet $changes): array
    {
        $rootFiles = array_filter($changes->paths(), fn (string $path): bool => ! str_contains($path, '/'));

        return array_slice(array_values($rootFiles), 0, self::MAX_LISTED_PROJECT_FILES);
    }
}
