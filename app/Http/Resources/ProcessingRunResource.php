<?php

namespace App\Http\Resources;

use App\Incremental\FullProcessingReason;
use App\Models\ProcessingRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A GitChronicle processing run as the „Istoric” tab shows it: a marker on the commit it
 * processed (sha = the run's to_sha), with what it did. The ProcessingRunItem type in TypeScript.
 *
 * For an update (Faza 9) it also carries what changed (the run's change_summary) and, when the
 * controller attached one (the "reference" relation), the numbers of the last complete processing
 * before it, so the page can show what the incremental update saved. Only real numbers: a value
 * that is not known is null and the page hides it.
 *
 * @mixin ProcessingRun
 */
class ProcessingRunResource extends JsonResource
{
    /**
     * At most this many affected modules are sent with a run (the rest are only counted).
     */
    public const MAX_LISTED_MODULES = 60;

    /**
     * Transform the resource into an array.
     *
     * cost_usd_equivalent is what the AI tokens would have cost through the API; for runs without
     * AI it is null.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sha' => $this->to_sha,
            'from_sha' => $this->from_sha,
            'mode' => $this->mode->value,
            'trigger' => $this->trigger->value,
            'status' => $this->status->value,
            'duration_ms' => $this->duration_ms,
            'files_total' => $this->files_total,
            'files_changed' => $this->files_changed,
            'files_analyzed' => $this->files_analyzed,
            'modules_total' => $this->modules_total,
            'modules_affected' => $this->modules_affected,
            'documents_regenerated' => $this->documents_regenerated,
            'documents_kept' => $this->documents_kept,
            'context_characters' => $this->context_characters,
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'cost_usd_equivalent' => $this->cost($this->resource),
            'changes' => $this->changes(),
            'reference' => $this->reference(),
            'started_at' => $this->started_at->toIso8601String(),
        ];
    }

    /**
     * What the run found changed since the previous processed commit, or null for a run that
     * compared nothing (the first processing, or a run from before the incremental update existed).
     *
     * full_reason is the English sentence (FullProcessingReason::sentence(), or the sentence an older run
     * saved); full_reason_facts holds the facts it is made of (a reason code and its numbers), null for
     * older runs, so the interface can word them in the visitor's language.
     *
     * @return array{added: int|null, modified: int|null, deleted: int|null, modules: list<array{path: string, files: int, via: string}>, modules_not_listed: int, modules_added: list<string>, modules_removed: list<string>, full_reason: string|null, full_reason_facts: array<string, int|string>|null}|null
     */
    private function changes(): ?array
    {
        $summary = $this->change_summary;

        if ($summary === null) {
            return null;
        }

        $modules = $summary['modules'] ?? [];

        return [
            'added' => $summary['added'] ?? null,
            'modified' => $summary['modified'] ?? null,
            'deleted' => $summary['deleted'] ?? null,
            'modules' => array_slice($modules, 0, self::MAX_LISTED_MODULES),
            'modules_not_listed' => max(0, count($modules) - self::MAX_LISTED_MODULES),
            'modules_added' => array_slice($summary['modules_added'] ?? [], 0, self::MAX_LISTED_MODULES),
            'modules_removed' => array_slice($summary['modules_removed'] ?? [], 0, self::MAX_LISTED_MODULES),
            'full_reason' => FullProcessingReason::sentence($summary['full_reason'] ?? null),
            'full_reason_facts' => is_array($summary['full_reason'] ?? null) ? $summary['full_reason'] : null,
        ];
    }

    /**
     * The last complete processing (first or full) that succeeded before this incremental run:
     * what the same repository cost when everything was processed. Null when there is none.
     *
     * @return array{sha: string|null, mode: string, duration_ms: int|null, files_analyzed: int, documents_regenerated: int, context_characters: int, input_tokens: int, output_tokens: int, cost_usd_equivalent: float|null}|null
     */
    private function reference(): ?array
    {
        $reference = $this->resource->relationLoaded('reference') ? $this->resource->getRelation('reference') : null;

        if (! $reference instanceof ProcessingRun) {
            return null;
        }

        return [
            'sha' => $reference->to_sha,
            'mode' => $reference->mode->value,
            'duration_ms' => $reference->duration_ms,
            'files_analyzed' => $reference->files_analyzed,
            'documents_regenerated' => $reference->documents_regenerated,
            'context_characters' => $reference->context_characters,
            'input_tokens' => $reference->input_tokens,
            'output_tokens' => $reference->output_tokens,
            'cost_usd_equivalent' => $this->cost($reference),
        ];
    }

    /**
     * The API-equivalent cost of a run as a number, or null when no AI call reported one.
     */
    private function cost(ProcessingRun $run): ?float
    {
        $cost = $run->getAttributes()['cost_usd_equivalent'] ?? null;

        return $cost === null ? null : (float) $cost;
    }
}
