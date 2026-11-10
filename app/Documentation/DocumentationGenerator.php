<?php

namespace App\Documentation;

use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Models\Document;
use App\Models\Module;
use App\Models\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Writes the documentation of a repository, one page at a time. The GenerateDocumentation job (pipeline)
 * and the repositories:document command both use it.
 *
 * For each page:
 *
 * 1. ContextBuilder builds its context and the sha256 of it (the input hash);
 * 2. the page is skipped when the saved page has the same input hash, unless it would now be written
 *    better: a page of the template is written again by Claude once AI is available, but a page of
 *    Claude is never replaced by the template while its input is the same;
 * 3. Claude writes it (AiDocumentationWriter) or, with AI turned off, the template does;
 * 4. if Claude fails, the template writes this page instead and it is marked stale with the reason; an
 *    error that will repeat (a usage limit, a timeout, an overloaded API: AiException::$retryable)
 *    pauses Claude for the rest of the run, so the run does not wait for it page after page;
 * 5. the page is saved, and a new DocumentVersion is added when its Markdown changed.
 */
final class DocumentationGenerator
{
    /**
     * Why Claude is not called for the rest of this run, or null while it is.
     */
    private ?string $aiPausedReason = null;

    /**
     * Create the generator. TextGenerator is the one chosen by AI_DRIVER (AppServiceProvider).
     */
    public function __construct(
        private ContextBuilder $contexts,
        private TemplateDocumentationWriter $template,
        private TextGenerator $textGenerator,
    ) {}

    /**
     * Which pages the repository's documentation has (see DocumentationPlan).
     */
    public function plan(Repository $repository): DocumentationPlan
    {
        return DocumentationPlan::for($repository);
    }

    /**
     * Write one page of the plan (or leave it as it is when its input did not change).
     *
     * @param  bool  $force  write it even when its input did not change
     *
     * @throws InvalidArgumentException for a slug that is not a page of the plan
     */
    public function write(DocumentationPlan $plan, string $slug, bool $force = false): PageResult
    {
        if (! in_array($slug, $plan->slugs(), true)) {
            throw new InvalidArgumentException("The documentation plan has no page \"{$slug}\".");
        }

        $repository = $plan->repository;
        $module = $plan->moduleFor($slug);
        $context = $module !== null ? $this->contexts->module($module) : $this->contexts->overview($plan);
        $inputHash = $context->inputHash();

        $document = Document::whereBelongsTo($repository)->firstWhere('slug', $slug);
        $useAi = $this->aiIsOn() && $this->aiPausedReason === null;

        if (! $force && $document !== null && $document->input_hash === $inputHash
            && ($document->writer === Document::WRITER_AI || ! $useAi)) {
            if ($module !== null && $document->module_id !== $module->id) {
                $document->update(['module_id' => $module->id]);
            }

            return PageResult::unchanged($slug);
        }

        $links = SourceLinks::for($repository);
        $aiError = $this->aiIsOn() ? $this->aiPausedReason : null;
        $rejectedAnswer = null;

        try {
            $page = $this->writePage($useAi ? new AiDocumentationWriter($this->textGenerator) : $this->template, $slug, $context, $links);
        } catch (AiException $exception) {
            Log::warning('Claude could not write a documentation page; the template wrote it instead.', [
                'repository_id' => $repository->id,
                'slug' => $slug,
                'reason' => $exception->getMessage(),
            ]);

            $aiError = $exception->getMessage();
            $rejectedAnswer = $exception instanceof UnusableAiPage ? $exception->result : null;

            if ($exception->retryable) {
                $this->aiPausedReason = $exception->getMessage();
            }

            $page = $this->writePage($this->template, $slug, $context, $links);
        }

        $this->save($repository, $module, $document, $slug, $page, $inputHash, $aiError);

        return new PageResult(
            slug: $slug,
            written: true,
            writer: $page->writer,
            inputTokens: $page->inputTokens + ($rejectedAnswer->inputTokens ?? 0),
            outputTokens: $page->outputTokens + ($rejectedAnswer->outputTokens ?? 0),
            costUsdEquivalent: $this->addCosts($page->costUsdEquivalent, $rejectedAnswer),
            aiError: $aiError,
        );
    }

    /**
     * After every page of the plan was written: remove the pages of modules that no longer have one
     * (deleted folders, or modules that fell under the page limit) and remember when the documentation was written.
     */
    public function finish(DocumentationPlan $plan): void
    {
        Document::whereBelongsTo($plan->repository)->whereNotIn('slug', $plan->slugs())->delete();

        $plan->repository->update(['documented_at' => now()]);
    }

    /**
     * Continue a run in which Claude was already paused (the reason comes from the previous job of the run).
     */
    public function pauseAi(?string $reason): void
    {
        $this->aiPausedReason = $reason;
    }

    /**
     * Why Claude is paused for the rest of the run, or null.
     */
    public function aiPausedReason(): ?string
    {
        return $this->aiPausedReason;
    }

    /**
     * Whether an AI driver is configured: NullTextGenerator is what AppServiceProvider binds when AI is off
     * (AI_DRIVER=none, or "claude" without an API key); then the template writes every page, and that is not a failure.
     */
    private function aiIsOn(): bool
    {
        return ! $this->textGenerator instanceof NullTextGenerator;
    }

    /**
     * Ask a writer for the page with this slug.
     */
    private function writePage(DocumentationWriter $writer, string $slug, ModuleContext|OverviewContext $context, SourceLinks $links): WrittenPage
    {
        return match (true) {
            $context instanceof ModuleContext => $writer->module($context, $links),
            $slug === Document::README => $writer->readme($context, $links),
            default => $writer->overview($context, $links),
        };
    }

    /**
     * Save the page (and a new version when its Markdown changed), in one transaction.
     *
     * @param  string|null  $aiError  why Claude did not write it while AI is on: the page is then stale
     */
    private function save(Repository $repository, ?Module $module, ?Document $document, string $slug, WrittenPage $page, string $inputHash, ?string $aiError): void
    {
        DB::transaction(function () use ($repository, $module, $document, $slug, $page, $inputHash, $aiError): void {
            $document ??= new Document(['slug' => $slug]);
            $document->repository()->associate($repository);
            $markdownChanged = $document->markdown !== $page->markdown;

            $document->fill([
                'module_id' => $module?->id,
                'title' => $module?->path ?? ($slug === Document::README ? 'README generat' : 'Despre proiect'),
                'markdown' => $page->markdown,
                'source_sha' => $repository->last_sha,
                'input_hash' => $inputHash,
                'writer' => $page->writer,
                'model' => $page->model,
                'ai_driver' => $page->aiDriver,
                'input_tokens' => $page->inputTokens,
                'output_tokens' => $page->outputTokens,
                'cost_usd_equivalent' => $page->costUsdEquivalent,
                'generated_at' => now(),
                'is_stale' => $aiError !== null,
                'stale_reason' => $aiError === null ? null : "Claude nu a putut scrie această pagină: {$aiError} Până la următoarea procesare, pagina e scrisă automat din analiza codului.",
            ])->save();

            if ($markdownChanged) {
                $document->versions()->create(['sha' => $repository->last_sha, 'markdown' => $page->markdown]);
            }
        });
    }

    /**
     * The cost of the page plus the cost of a rejected answer, or null when neither reported one.
     */
    private function addCosts(?float $pageCost, ?AiResult $rejectedAnswer): ?float
    {
        if ($pageCost === null && $rejectedAnswer?->costUsdEquivalent === null) {
            return null;
        }

        return ($pageCost ?? 0) + ($rejectedAnswer->costUsdEquivalent ?? 0);
    }
}
