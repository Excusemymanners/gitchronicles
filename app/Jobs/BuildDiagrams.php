<?php

namespace App\Jobs;

use App\Diagrams\ArchitectureDiagramBuilder;
use App\Diagrams\DiagramHistory;
use App\Diagrams\FlowDiagramWriter;
use App\Enums\DiagramType;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * The diagrams step of the pipeline (Faza 7), after the documentation:
 *
 * 1. the architecture diagrams (layers, C4 containers, components, context) are drawn from the
 *    code analysis and the clone's manifests and replace the previous ones, in one transaction;
 * 2. Claude writes the „Cum funcționează” flows, best effort: without AI, or when Claude fails,
 *    the step still succeeds and the page shows no flows. Its tokens and their cost are added to the run;
 * 3. what changed in the diagrams is saved as new versions (DiagramHistory), for the time travel.
 *
 * At an update (Faza 9) nothing special is needed here: the architecture views are drawn again from
 * the analysis in milliseconds, and Claude is asked for the flows only when the code it sees changed
 * (the input hash of FlowDiagramWriter), so an update that does not touch the entry files costs no tokens.
 *
 * It needs only the analysis, never the documentation, and it is idempotent: running it again
 * for the same commit gives the same diagrams (and does not ask Claude again, see FlowDiagramWriter).
 * Every diagram remembers the commit it was drawn from (source_sha = repositories.last_sha).
 */
class BuildDiagrams implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt: drawing does not use the network, and Claude's part never fails the step.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: Claude's own limit (300 s) plus a margin.
     * It stays under the queue's retry_after (360 s), so no second worker takes the same job.
     */
    public int $timeout = 340;

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
    public function handle(ArchitectureDiagramBuilder $builder, FlowDiagramWriter $flowWriter, DiagramHistory $history): void
    {
        $this->saveArchitectureDiagrams($builder->build($this->repository));

        $aiResult = $flowWriter->refresh($this->repository);

        if ($aiResult !== null) {
            $this->run->incrementEach(['input_tokens' => $aiResult->inputTokens, 'output_tokens' => $aiResult->outputTokens]);

            // The cost goes with the tokens, so the run's cost and its tokens describe the same calls.
            if ($aiResult->costUsdEquivalent !== null) {
                $this->run->update(['cost_usd_equivalent' => (float) $this->run->cost_usd_equivalent + $aiResult->costUsdEquivalent]);
            }
        }

        $history->record($this->repository, $this->run);
    }

    /**
     * Replace the architecture diagrams with the new ones; a view that has no data any more is deleted.
     * The flows are not touched here: FlowDiagramWriter owns them.
     *
     * @param  list<array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}>  $diagrams
     */
    private function saveArchitectureDiagrams(array $diagrams): void
    {
        DB::transaction(function () use ($diagrams): void {
            $this->repository->diagrams()
                ->where('type', '!=', DiagramType::Flow)
                ->whereNotIn('type', array_map(fn (array $diagram): string => $diagram['type']->value, $diagrams))
                ->delete();

            foreach ($diagrams as $diagram) {
                $this->repository->diagrams()->updateOrCreate(
                    ['type' => $diagram['type'], 'key' => $diagram['key']],
                    ['mermaid' => $diagram['mermaid'], 'meta' => $diagram['meta'], 'source_sha' => $this->repository->last_sha],
                );
            }
        });
    }
}
