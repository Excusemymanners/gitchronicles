<?php

namespace App\Http\Resources;

use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only place that decides what the frontend receives about a repository
 * (the RepositoryItem type in TypeScript). user_id is never sent.
 *
 * Pass it to Inertia with ->resolve(): it returns a plain array. Without it,
 * Inertia would build a JSON response and the prop would be wrapped in "data".
 *
 * @mixin Repository
 */
class RepositoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * "is_processing" tells the page to keep refreshing itself (RepositoryStatus::isProcessing()).
     *
     * @return array{id: int, url: string, owner: string, name: string, full_name: string, description: string|null, status: string, status_label: string, is_processing: bool, default_branch: string|null, last_sha: string|null, error: string|null, cloned_at: string|null, analyzed_at: string|null, documented_at: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'owner' => $this->owner,
            'name' => $this->name,
            'full_name' => "{$this->owner}/{$this->name}",
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_processing' => $this->status->isProcessing(),
            'default_branch' => $this->default_branch,
            'last_sha' => $this->last_sha,
            'error' => StoredMessage::render($this->error),
            'cloned_at' => $this->cloned_at?->toIso8601String(),
            'analyzed_at' => $this->analyzed_at?->toIso8601String(),
            'documented_at' => $this->documented_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
