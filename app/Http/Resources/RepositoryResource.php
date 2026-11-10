<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only place that decides what the frontend receives about a repository
 * (the RepositoryItem type in TypeScript).
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
     * @return array{id: int, url: string, owner: string, name: string, full_name: string, status: string, status_label: string, last_sha: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'owner' => $this->owner,
            'name' => $this->name,
            'full_name' => "{$this->owner}/{$this->name}",
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'last_sha' => $this->last_sha,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
