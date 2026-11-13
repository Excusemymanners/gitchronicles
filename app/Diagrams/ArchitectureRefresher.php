<?php

namespace App\Diagrams;

use App\Ai\AiResult;
use App\Enums\DiagramType;
use App\Models\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Draws the architecture diagrams of a repository again, with their findings, evidence and
 * interpretation, and saves them in place of the previous ones. The BuildDiagrams step of the
 * pipeline and the `repositories:diagrams` command both use it.
 */
final class ArchitectureRefresher
{
    /**
     * Create a new refresher.
     */
    public function __construct(
        private ArchitectureDiagramBuilder $builder,
        private ArchitectureReader $reader,
    ) {}

    /**
     * Replace the architecture diagrams with the new ones; a view that has no data any more is deleted.
     * The flows and the big picture are not touched here: FlowDiagramWriter and SystemMapWriter own them.
     *
     * Returns the types of the diagrams saved, where their reading came from ("ai", "cache", "fallback"
     * or "none") and Claude's answer when it was asked.
     *
     * @return array{types: list<string>, origin: string, result: AiResult|null}
     */
    public function refresh(Repository $repository): array
    {
        $reading = $this->reader->read($repository, $this->builder->build($repository));
        $diagrams = $reading['diagrams'];

        DB::transaction(function () use ($repository, $diagrams): void {
            $repository->diagrams()
                ->whereNotIn('type', [DiagramType::Flow, DiagramType::Overview])
                ->whereNotIn('type', array_map(fn (array $diagram): string => $diagram['type']->value, $diagrams))
                ->delete();

            foreach ($diagrams as $diagram) {
                $repository->diagrams()->updateOrCreate(
                    ['type' => $diagram['type'], 'key' => $diagram['key']],
                    ['mermaid' => $diagram['mermaid'], 'meta' => $diagram['meta'], 'source_sha' => $repository->last_sha],
                );
            }
        });

        return [
            'types' => array_map(fn (array $diagram): string => $diagram['type']->value, $diagrams),
            'origin' => $reading['origin'],
            'result' => $reading['result'],
        ];
    }
}
