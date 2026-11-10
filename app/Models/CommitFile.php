<?php

namespace App\Models;

use Database\Factories\CommitFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file changed by a commit, with the lines added and removed in it
 * (null for a binary file, or when the line counts could not be read).
 */
#[Fillable(['path', 'insertions', 'deletions'])]
#[WithoutTimestamps]
class CommitFile extends Model
{
    /** @use HasFactory<CommitFileFactory> */
    use HasFactory;

    /**
     * The commit that changed this file.
     *
     * @return BelongsTo<Commit, $this>
     */
    public function commit(): BelongsTo
    {
        return $this->belongsTo(Commit::class);
    }
}
