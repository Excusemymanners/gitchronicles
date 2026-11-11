<?php

namespace App\Jobs;

use App\Git\ChangeSet;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Search\CodeIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A step of the pipeline (Faza 8): cut the analyzed files into chunks and rebuild the search index
 * the assistant uses to answer questions about the code (CodeIndexer).
 *
 * It does not change the repository's status: indexing is quick and needs no step of its own on the page.
 * The job is idempotent: every indexing replaces the previous one of the same repository.
 *
 * An incremental run (Faza 9) cuts again only the files that changed between its two commits
 * (CodeIndexer::reindex()); the first processing, a Full run and a repository that has no index yet
 * index everything.
 */
class IndexCode implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt: indexing reads only the clone and the database, so an error would happen again.
     * After a failure the user can press „Reîncearcă”.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run.
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
    public function handle(CodeIndexer $indexer): void
    {
        $changes = $this->run->isIncremental() && $this->repository->codeChunks()->exists()
            ? ChangeSet::between($this->repository->clonePath(), (string) $this->run->from_sha, (string) $this->run->to_sha)
            : null;

        $summary = $changes === null
            ? $indexer->index($this->repository)
            : $indexer->reindex($this->repository, $changes);

        Log::info('The code was indexed for the assistant.', [
            'repository_id' => $this->repository->id,
            'processing_run_id' => $this->run->id,
            'only_changed_files' => $changes !== null,
            ...$summary,
        ]);
    }
}
