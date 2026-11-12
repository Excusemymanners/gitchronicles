<?php

namespace App\Console\Commands;

use App\Documentation\DocumentationGenerator;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Writes the documentation of one analyzed repository by hand, right here in the terminal (no queue),
 * page by page, with what each page cost. Useful after changing the prompt or the template (--force)
 * and to write only some pages (--page).
 *
 *     php artisan repositories:document 1
 *     php artisan repositories:document 1 --force --page=overview --page=app-http
 *
 * It does not create a processing run: the runs measure the pipeline. Pages whose input did not change
 * are skipped, as in the pipeline.
 */
#[Signature('repositories:document
    {repository : The id of the repository}
    {--force : Write every page again, even when its input did not change}
    {--page=* : Write only these pages (slugs such as overview, readme, app-http)}')]
#[Description('Write the documentation (overview, module pages, README) of an analyzed repository')]
class DocumentRepository extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DocumentationGenerator $generator): int
    {
        $repository = Repository::find($this->argument('repository'));

        if ($repository === null) {
            $this->error("There is no repository with id {$this->argument('repository')}.");

            return self::FAILURE;
        }

        $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

        if ($repository->analyzed_at === null) {
            $this->error("{$name} has no code analysis yet. Start the processing first: php artisan repositories:process {$repository->id}");

            return self::FAILURE;
        }

        if ($repository->isBeingProcessed()) {
            $this->warn("{$name} is being processed right now, leaving it alone.");

            return self::FAILURE;
        }

        $plan = $generator->plan($repository);
        $requestedSlugs = $this->option('page');
        $unknownSlugs = array_diff($requestedSlugs, $plan->slugs());

        if ($unknownSlugs !== []) {
            $this->error('Pagini necunoscute: '.implode(', ', $unknownSlugs).'. Paginile acestui repository: '.implode(', ', $plan->slugs()));

            return self::FAILURE;
        }

        $slugs = $requestedSlugs === [] ? $plan->slugs() : array_values(array_intersect($plan->slugs(), $requestedSlugs));
        $written = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $cost = null;

        foreach ($slugs as $slug) {
            $page = $generator->write($plan, $slug, (bool) $this->option('force'));

            $written += $page->written ? 1 : 0;
            $inputTokens += $page->inputTokens;
            $outputTokens += $page->outputTokens;
            $cost = $page->costUsdEquivalent === null ? $cost : ($cost ?? 0) + $page->costUsdEquivalent;

            $this->line(match (true) {
                $page->outdated => "  {$slug}: the code changed, but Claude's old text stays, marked as outdated".($page->aiError === null ? ' (AI is off)' : " (Claude: {$page->aiError})"),
                ! $page->written => "  {$slug}: unchanged",
                $page->writer === 'ai' => "  {$slug}: written by Claude ({$page->inputTokens} input tokens, {$page->outputTokens} output tokens"
                    .($page->costUsdEquivalent === null ? '' : ', '.number_format($page->costUsdEquivalent, 4).' $ on the API').')',
                default => "  {$slug}: written from the code analysis".($page->aiError === null ? '' : " (Claude: {$page->aiError})"),
            });
        }

        if ($requestedSlugs === []) {
            $generator->finish($plan);
        }

        $this->info("{$name}: {$written} of ".count($slugs).' pages written; '
            ."{$inputTokens} input tokens, {$outputTokens} output tokens"
            .($cost === null ? '.' : ', '.number_format($cost, 4).' $ if it had been on the API.'));

        return self::SUCCESS;
    }
}
