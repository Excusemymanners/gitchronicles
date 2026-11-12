<?php

namespace App\Console\Commands;

use App\Diagrams\ArchitectureRefresher;
use App\Diagrams\FlowDiagramWriter;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Draws the architecture diagrams of existing repositories again, with their findings, the evidence
 * of every arrow and the interpretation, without touching the documentation or the media. The „Cum
 * funcționează” flows are written again only when what Claude would see has changed (their input hash).
 *
 *     php artisan repositories:diagrams 3
 *     php artisan repositories:diagrams --all
 *
 * For every repository it prints the diagrams rebuilt and where the interpretation came from: Claude,
 * the cache (the same input was already read) or the fallback composed from the findings.
 */
#[Signature('repositories:diagrams
    {repository? : The id of the repository}
    {--all : Refresh every analyzed repository}')]
#[Description('Draw the architecture diagrams again, with findings, evidence and interpretation')]
class RefreshRepositoryDiagrams extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ArchitectureRefresher $refresher, FlowDiagramWriter $flowWriter): int
    {
        if (($this->argument('repository') === null) === (! $this->option('all'))) {
            $this->error('Give the id of a repository, or --all.');

            return self::FAILURE;
        }

        $repositories = $this->option('all')
            ? Repository::whereNotNull('analyzed_at')->orderBy('id')->get()
            : Repository::whereKey($this->argument('repository'))->get();

        if ($repositories->isEmpty()) {
            $this->error('There is no such repository.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($repositories as $repository) {
            $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

            if ($repository->analyzed_at === null || $repository->isBeingProcessed()) {
                $this->warn("{$name}: not analyzed yet or being processed, skipped.");
                $failed = true;

                continue;
            }

            $outcome = $refresher->refresh($repository);
            $flowCall = $flowWriter->refresh($repository);

            $this->line(sprintf(
                '%s: %s; interpretation: %s%s.',
                $name,
                $outcome['types'] === [] ? 'no architecture diagrams' : 'rebuilt '.implode(', ', $outcome['types']),
                match ($outcome['origin']) {
                    'ai' => 'written by Claude',
                    'cache' => 'from the cache, nothing sent to Claude',
                    'fallback' => 'composed from the findings, without AI',
                    default => 'none',
                },
                $flowCall === null ? '' : '; flows written again',
            ));
        }

        return $failed && $this->argument('repository') !== null ? self::FAILURE : self::SUCCESS;
    }
}
