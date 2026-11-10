<?php

namespace App\Models;

use Database\Factories\CommitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A commit of the repository, imported from the clone's git history (ImportCommits):
 * its author, date, subject and how many files and lines it changed.
 *
 * A merge commit (is_merge) has no counters: git shows no changes for it.
 * The counters are also null when the line counts could not be read (see CommitHistory).
 */
#[Fillable([
    'sha', 'parent_sha', 'is_merge', 'author_name', 'authored_at', 'subject',
    'files_changed', 'insertions', 'deletions',
])]
#[WithoutTimestamps]
class Commit extends Model
{
    /** @use HasFactory<CommitFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_merge' => 'boolean',
            'authored_at' => 'datetime',
        ];
    }

    /**
     * The repository this commit belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The files this commit changed.
     *
     * @return HasMany<CommitFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(CommitFile::class);
    }
}
