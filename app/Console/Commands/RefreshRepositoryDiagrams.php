<?php

namespace App\Console\Commands;

use App\Analysis\RepositoryAnalyzer;
use App\Diagrams\ArchitectureRefresher;
use App\Diagrams\FlowDiagramWriter;
use App\Diagrams\SystemMapWriter;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Draws every diagram of existing repositories again, without touching the documentation or the media:
 * the links the imports do not show are found again (from the saved analysis and the clone), then the
 * architecture diagrams, the big picture ("How <name> works") and the flows ("What happens when …?").
 * The text generator is asked again only when what it would see has changed (the input hashes).
 *
 *     php artisan repositories:diagrams 3
 *     php artisan repositories:diagrams --all
 *
 * For every repository it prints how many links were found, and for the big picture and the flows where
 * they came from (Claude, the cache or the drawing without AI) and how many proposed arrows or messages
 * were dropped because the code does not back them.
 */
#[Signature('repositories:diagrams
    {repository? : The id of the repository}
    {--all : Refresh every analyzed repository}')]
#[Description('Draw every diagram again: links in the code, big picture, flows, architecture views')]
class RefreshRepositoryDiagrams extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RepositoryAnalyzer $analyzer, ArchitectureRefresher $refresher, SystemMapWriter $mapWriter, FlowDiagramWriter $flowWriter): int
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

            $links = is_dir($repository->clonePath()) ? $analyzer->rescanLinks($repository) : null;
            $map = $mapWriter->refresh($repository);
            $outcome = $refresher->refresh($repository);
            $flows = $flowWriter->refresh($repository);

            $this->line("{$name}:");
            $this->line('  links in the code: '.($links === null ? 'the clone is missing, kept as they were' : "{$links} found"));
            $this->line('  big picture: '.$this->origin($map['origin'], $map['reason']).$this->dropped($map['stats'], 'proposed_edges', 'dropped_edges', 'arrows'));
            $this->line('  flows: '.$this->origin($flows['origin'], $flows['reason']).$this->dropped($flows['stats'], 'proposed', 'dropped', 'messages'));
            $this->line(sprintf(
                '  architecture: %s; interpretation: %s.',
                $outcome['types'] === [] ? 'no diagrams' : 'rebuilt '.implode(', ', $outcome['types']),
                $outcome['origin'] === 'fallback' ? 'composed from the findings, without AI' : $this->origin($outcome['origin'], null),
            ));
        }

        return $failed && $this->argument('repository') !== null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Where a diagram came from, in words.
     */
    private function origin(string $origin, ?string $reason): string
    {
        return match ($origin) {
            'ai' => 'written by Claude',
            'cache' => 'from the cache, nothing sent to Claude',
            'kept' => 'kept (written by Claude for this commit)',
            'fallback' => 'drawn without AI'.($reason === null ? '' : " ({$reason})"),
            default => 'none',
        };
    }

    /**
     * How many of the proposed arrows or messages were dropped, when Claude proposed some.
     *
     * @param  array<string, int>|null  $stats
     */
    private function dropped(?array $stats, string $proposedKey, string $droppedKey, string $what): string
    {
        if ($stats === null || ($stats[$proposedKey] ?? 0) === 0) {
            return '';
        }

        return "; {$stats[$droppedKey]} of {$stats[$proposedKey]} proposed {$what} dropped";
    }
}
