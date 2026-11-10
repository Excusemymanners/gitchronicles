<?php

namespace App\Http\Resources;

use App\Models\Commit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commit as the „Istoric” tab shows it (the CommitItem type in TypeScript).
 *
 * @mixin Commit
 */
class CommitResource extends JsonResource
{
    /**
     * Transform the resource into an array. The counters are null for a merge commit and when
     * git could not count the lines.
     *
     * @return array{id: int, sha: string, is_merge: bool, author_name: string, authored_at: string, subject: string, files_changed: int|null, insertions: int|null, deletions: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sha' => $this->sha,
            'is_merge' => $this->is_merge,
            'author_name' => $this->author_name,
            'authored_at' => $this->authored_at->toIso8601String(),
            'subject' => $this->subject,
            'files_changed' => $this->files_changed,
            'insertions' => $this->insertions,
            'deletions' => $this->deletions,
        ];
    }
}
