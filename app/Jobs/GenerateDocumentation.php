<?php

namespace App\Jobs;

use App\Documentation\DocumentationGenerator;
use App\Documentation\PageResult;
use App\Enums\RepositoryStatus;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Step 3 of the pipeline: write the documentation (Faza 6): the module pages, then the overview,
 * then the generated README (see DocumentationGenerator). The run records how many pages were written
 * (documents_regenerated), the AI tokens and what they would have cost on the API.
 *
 * One Claude call may take up to 5 minutes, and a repository has up to 42 pages, but the queue hands
 * a job to another worker after 6 minutes (retry_after in config/queue.php). So a job starts a new page
 * only during its first TIME_BUDGET_SECONDS; then it puts a new GenerateDocumentation job with the pages
 * still to write in front of the chain and ends. That job continues the same run: the next pipeline
 * step starts only after the last page. Without AI every page takes milliseconds, so one job does all.
 */
class GenerateDocumentation implements ShouldQueue
{
    use Queueable;

    /**
     * A job starts a new page only while less than this many seconds have passed since it started.
     */
    public const TIME_BUDGET_SECONDS = 30;

    /**
     * One attempt: a failed Claude call does not fail the job (the template writes that page),
     * so an exception here is a bug, and the same input would fail again.
     */
    public int $tries = 1;

    /**
     * 30 s + one Claude call (at most 300 s) + saving, below the queue's retry_after (360 s).
     */
    public int $timeout = 350;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param  list<string>|null  $remainingSlugs  the pages still to write; null for the first job of the run, which plans them
     * @param  string|null  $aiPausedReason  why Claude is paused for the rest of the run (set by an earlier job of the run)
     */
    public function __construct(
        public Repository $repository,
        public ProcessingRun $run,
        public ?array $remainingSlugs = null,
        public ?string $aiPausedReason = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DocumentationGenerator $generator): void
    {
        $startedAt = now();

        if ($this->remainingSlugs === null) {
            $this->repository->update(['status' => RepositoryStatus::Documenting]);
        }

        $plan = $generator->plan($this->repository);
        $slugs = $this->remainingSlugs ?? $plan->slugs();
        $generator->pauseAi($this->aiPausedReason);

        while ($slugs !== [] && $startedAt->diffInSeconds(now()) < self::TIME_BUDGET_SECONDS) {
            $this->record($generator->write($plan, array_shift($slugs)));
        }

        if ($slugs !== []) {
            $this->prependToChain(new self($this->repository, $this->run, $slugs, $generator->aiPausedReason()));

            return;
        }

        $generator->finish($plan);
    }

    /**
     * Add a page's numbers to the run: one more page written, the tokens and the cost.
     */
    private function record(PageResult $page): void
    {
        $this->run->incrementEach([
            'documents_regenerated' => $page->written ? 1 : 0,
            'input_tokens' => $page->inputTokens,
            'output_tokens' => $page->outputTokens,
        ]);

        if ($page->costUsdEquivalent !== null) {
            $this->run->update(['cost_usd_equivalent' => (float) $this->run->cost_usd_equivalent + $page->costUsdEquivalent]);
        }
    }
}
