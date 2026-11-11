<?php

namespace App\Models;

use Database\Factories\WatchedRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A public GitHub repository watched for the „Descoperă” leaderboard (bonus), found by `discover:refresh`.
 *
 * It is not a Repository: a Repository is one a user asked GitChronicle to document. A watched repository
 * becomes one only when someone presses „Documentează” on the page (the normal AddRepository flow).
 *
 * The columns hold what GitHub said the last time it was seen; the stars of every day are in its snapshots.
 */
#[Fillable([
    'github_id', 'full_name', 'description', 'language', 'topics', 'license', 'stars', 'forks',
    'github_created_at', 'github_pushed_at', 'first_seen_at', 'last_seen_at',
])]
class WatchedRepository extends Model
{
    /** @use HasFactory<WatchedRepositoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'github_created_at' => 'datetime',
            'github_pushed_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The stars of this repository, one row per day.
     *
     * @return HasMany<StarSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(StarSnapshot::class);
    }

    /**
     * The repository's page on GitHub.
     */
    public function githubUrl(): string
    {
        return "https://github.com/{$this->full_name}";
    }

    /**
     * The canonical URL GitChronicle stores for a documented repository (lowercase, see GitHubUrl),
     * used to find out whether this repository is already documented.
     */
    public function canonicalUrl(): string
    {
        return Str::lower($this->githubUrl());
    }
}
