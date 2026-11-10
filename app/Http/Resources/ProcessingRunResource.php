<?php

namespace App\Http\Resources;

use App\Models\ProcessingRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A GitChronicle processing run as the „Istoric” tab shows it: a marker on the commit it
 * processed (sha = the run's to_sha). The ProcessingRunItem type in TypeScript.
 *
 * @mixin ProcessingRun
 */
class ProcessingRunResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * cost_usd_equivalent is what the AI tokens would have cost through the API; its column
     * arrives with the documentation step, so until then (and for runs without AI) it is null.
     *
     * @return array{id: int, sha: string|null, mode: string, trigger: string, status: string, duration_ms: int|null, documents_regenerated: int, input_tokens: int, output_tokens: int, cost_usd_equivalent: float|null, started_at: string}
     */
    public function toArray(Request $request): array
    {
        $cost = $this->resource->getAttributes()['cost_usd_equivalent'] ?? null;

        return [
            'id' => $this->id,
            'sha' => $this->to_sha,
            'mode' => $this->mode->value,
            'trigger' => $this->trigger->value,
            'status' => $this->status->value,
            'duration_ms' => $this->duration_ms,
            'documents_regenerated' => $this->documents_regenerated,
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'cost_usd_equivalent' => $cost === null ? null : (float) $cost,
            'started_at' => $this->started_at->toIso8601String(),
        ];
    }
}
