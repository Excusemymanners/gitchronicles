<?php

namespace App\Jobs;

use App\Ai\AiResult;
use App\Diagrams\ArchitectureRefresher;
use App\Diagrams\DiagramHistory;
use App\Diagrams\FlowDiagramWriter;
use App\Diagrams\SystemMapWriter;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The diagrams step of the pipeline (Faza 7), after the documentation:
 *
 * 1. the architecture diagrams (layers, C4 containers, components, context) are drawn from the
 *    code analysis and the clone's manifests, with their findings, evidence and interpretation
 *    (ArchitectureRefresher), and replace the previous ones, in one transaction;
 * 2. the big picture, "How <name> works" (SystemMapWriter): proposed by Claude and checked arrow by
 *    arrow against the links and the evidence found in the code, or drawn without AI;
 * 3. the flows, "What happens when someone …?" (FlowDiagramWriter), as sequence diagrams, the main
 *    flow first, checked the same way, or the deterministic main flow without AI. The tokens of
 *    every call and their cost are added to the run; a failed call never fails the step;
 * 4. what changed in the diagrams is saved as new versions (DiagramHistory), for the time travel.
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
    public function handle(ArchitectureRefresher $refresher, SystemMapWriter $mapWriter, FlowDiagramWriter $flowWriter, DiagramHistory $history): void
    {
        // The big picture first: the dependency maps name the modules by the parts it found.
        $this->addUsage($mapWriter->refresh($this->repository)['result']);
        $this->addUsage($refresher->refresh($this->repository)['result']);
        $this->addUsage($flowWriter->refresh($this->repository)['result']);

        $history->record($this->repository, $this->run);
    }

    /**
     * Add the tokens (and their cost) of one Claude call to the run, if there was a call.
     */
    private function addUsage(?AiResult $aiResult): void
    {
        if ($aiResult === null) {
            return;
        }

        $this->run->incrementEach(['input_tokens' => $aiResult->inputTokens, 'output_tokens' => $aiResult->outputTokens]);

        // The cost goes with the tokens, so the run's cost and its tokens describe the same calls.
        if ($aiResult->costUsdEquivalent !== null) {
            $this->run->update(['cost_usd_equivalent' => (float) $this->run->cost_usd_equivalent + $aiResult->costUsdEquivalent]);
        }
    }
}
