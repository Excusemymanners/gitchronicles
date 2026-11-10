<?php

namespace App\Jobs;

use App\Analysis\RepositoryAnalyzer;
use App\Enums\RepositoryStatus;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Step 2 of the pipeline: analyze the code of the clone with tree-sitter (RepositoryAnalyzer)
 * and record on the run how much work it did: files_total (every file git tracks),
 * files_analyzed (the files saved by the analysis) and modules_total.
 *
 * The job is idempotent: every analysis replaces the previous one of the same repository.
 */
class AnalyzeRepository implements ShouldQueue
{
    use Queueable;

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
        $this->repository->update(['status' => RepositoryStatus::Analyzing]);

        $summary = $analyzer->analyze($this->repository);

        $this->run->update([
            'files_total' => $summary['files_total'],
            'files_analyzed' => $summary['files_analyzed'],
            'modules_total' => $summary['modules_total'],
        ]);
    }
}
