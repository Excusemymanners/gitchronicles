<?php

namespace App\Jobs;

use App\Documentation\DocumentationGenerator;
use App\Documentation\DocumentationPlan;
use App\Documentation\PageResult;
use App\Enums\RepositoryStatus;
use App\Incremental\ImpactAnalysis;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The documentation step of the pipeline: write the documentation (Faza 6): the module pages, then the overview,
 * then the generated README (see DocumentationGenerator). The run records how many pages were written
 * (documents_regenerated), the AI tokens and what they would have cost on the API.
 *
 * One Claude call may take up to 5 minutes, and a repository has up to 42 pages, but the queue hands
 * a job to another worker after 6 minutes (retry_after in config/queue.php). So a job starts a new page
 * only during its first TIME_BUDGET_SECONDS; then it puts a new GenerateDocumentation job with the pages
 * still to write in front of the chain and ends. That job continues the same run: the next pipeline
 * step starts only after the last page. Without AI every page takes milliseconds, so one job does all.
 *
 * An incremental run (Faza 9) does not look at every page, only at the ones the changes can reach
 * (see pagesToCheck()). A page that is looked at is still written only when its context really changed
 * (DocumentationGenerator compares the input hash), and the other pages keep their text and their commit
 * (source_sha). The run records the written pages (documents_regenerated) and the kept ones (documents_kept).
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
            $this->repository->update(['status' => $this->run->statusFor(RepositoryStatus::Documenting)]);
        }

        $plan = $generator->plan($this->repository);
        $slugs = $this->remainingSlugs ?? $this->pagesToCheck($plan);
        $generator->pauseAi($this->aiPausedReason);

        while ($slugs !== [] && $startedAt->diffInSeconds(now()) < self::TIME_BUDGET_SECONDS) {
            $slug = array_shift($slugs);

            $this->record($generator->write($plan, $slug, changeDescription: $this->changeDescription($plan, $slug), run: $this->run));
        }

        if ($slugs !== []) {
            $this->prependToChain(new self($this->repository, $this->run, $slugs, $generator->aiPausedReason()));

            return;
        }

        $generator->finish($plan);

        $this->run->update(['documents_kept' => max(0, count($plan->slugs()) - $this->run->documents_regenerated)]);
    }

    /**
     * The pages this run looks at, in the order of the plan. The first processing and a Full run look
     * at all of them. An incremental run looks only at:
     *
     * - the pages of the affected modules (the run's change_summary, found by ImpactAnalysis);
     * - the pages that do not exist yet (a new module, or one that just got its own page);
     * - the pages marked stale: Claude could not write them last time, so it tries again;
     * - the overview and the README, but only when the project as a whole changed (projectChanged()).
     *
     * @return list<string>
     */
    private function pagesToCheck(DocumentationPlan $plan): array
    {
        if (! $this->run->isIncremental()) {
            return $plan->slugs();
        }

        $affectedModules = $this->run->affectedModules();
        $documents = Document::whereBelongsTo($this->repository)->get(['slug', 'is_stale'])->keyBy('slug');

        $pageListChanged = array_diff($plan->slugs(), $documents->keys()->all()) !== []
            || $documents->keys()->diff($plan->slugs())->isNotEmpty();
        $refreshOverview = $pageListChanged || $this->projectChanged();

        return array_values(array_filter($plan->slugs(), function (string $slug) use ($plan, $affectedModules, $documents, $refreshOverview): bool {
            $module = $plan->moduleFor($slug);
            $isAffected = $module === null ? $refreshOverview : isset($affectedModules[$module->path]);

            return $isAffected || ! $documents->has($slug) || $documents[$slug]->is_stale;
        }));
    }

    /**
     * Whether the changes are about the project as a whole, so the overview and the README must be
     * checked too: a module appeared or disappeared, a file of the root folder changed (the README,
     * a package manifest), or at least config('gitchronicle.incremental.overview_share') of the
     * modules have changed files.
     *
     * A small change inside a few modules does not count: the overview describes the project, not the
     * details of a module, and it is the most expensive page, so it is not written at every commit.
     */
    private function projectChanged(): bool
    {
        $changes = $this->run->change_summary ?? [];

        if (($changes['modules_added'] ?? []) !== [] || ($changes['modules_removed'] ?? []) !== [] || ($changes['project_files'] ?? []) !== []) {
            return true;
        }

        $changedModules = count(array_filter($this->run->affectedModules(), fn (array $module): bool => $module['via'] === ImpactAnalysis::VIA_CHANGED));

        return $changedModules >= (float) config('gitchronicle.incremental.overview_share') * max(1, $this->run->modules_total);
    }

    /**
     * What this run changed for a page, as the start of an English sentence, for the reason shown on a
     * page that could not be written again ("The module's code changed at commit abc1234 (3 files)").
     * Null when the run brings no change for the page (the first processing, or a page checked only
     * because it was already stale).
     */
    private function changeDescription(DocumentationPlan $plan, string $slug): ?string
    {
        if ($this->run->from_sha === null || $this->run->to_sha === null) {
            return null;
        }

        $commit = substr($this->run->to_sha, 0, 7);
        $module = $plan->moduleFor($slug);

        if ($module === null) {
            return $this->run->isIncremental() && ! $this->projectChanged() ? null : "The project changed at commit {$commit}";
        }

        $change = $this->run->affectedModules()[$module->path] ?? null;

        return match (true) {
            $change === null => null,
            $change['files'] === 1 => "The module's code changed at commit {$commit} (1 file)",
            $change['files'] > 1 => "The module's code changed at commit {$commit} ({$change['files']} files)",
            default => "The module's links to other modules changed at commit {$commit}",
        };
    }

    /**
     * Add a page's numbers to the run: one more page written, the tokens and the cost.
     */
    private function record(PageResult $page): void
    {
        $this->run->incrementEach([
            'documents_regenerated' => $page->written ? 1 : 0,
            'context_characters' => $page->contextCharacters,
            'input_tokens' => $page->inputTokens,
            'output_tokens' => $page->outputTokens,
        ]);

        if ($page->costUsdEquivalent !== null) {
            $this->run->update(['cost_usd_equivalent' => (float) $this->run->cost_usd_equivalent + $page->costUsdEquivalent]);
        }
    }
}
