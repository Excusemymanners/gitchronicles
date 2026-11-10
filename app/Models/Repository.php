<?php

namespace App\Models;

use App\Enums\ProcessingRunStatus;
use App\Enums\RepositoryStatus;
use App\Search\CodeSearch;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A public GitHub repository: one record per repository (the URL is unique),
 * visible to everyone, with or without an account.
 *
 * `user_id` is the user who added it. It is not fillable, so a request can never
 * choose it: the code sets it through the relationship ($user->repositories()->create()).
 *
 * `last_sha` is the last processed commit: the clone job fills it (Faza 3)
 * and the incremental update starts from it (Faza 9).
 *
 * The code analysis (Faza 5) fills files, modules and moduleDependencies, plus `analyzed_at`
 * and a short `description` taken from the README.
 *
 * The clone lives in storage/app/private/repositories/{id} and is deleted together with the record.
 * So is the search index of its code (Faza 8, CodeSearch::forget()).
 */
#[Fillable([
    'url', 'owner', 'name', 'description', 'status', 'default_branch', 'last_sha', 'error',
    'cloned_at', 'analyzed_at', 'documented_at',
])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * Delete the clone and the search index of its code together with the repository, whoever deletes it.
     */
    protected static function booted(): void
    {
        static::deleted(function (Repository $repository): void {
            Storage::disk('local')->deleteDirectory($repository->cloneDirectory());

            app(CodeSearch::class)->forget($repository);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RepositoryStatus::class,
            'cloned_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'documented_at' => 'datetime',
        ];
    }

    /**
     * The user who added the repository: null when that user deleted their account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every execution of the processing pipeline for this repository.
     *
     * @return HasMany<ProcessingRun, $this>
     */
    public function processingRuns(): HasMany
    {
        return $this->hasMany(ProcessingRun::class);
    }

    /**
     * The files read by the last code analysis (AnalyzeRepository).
     *
     * @return HasMany<RepositoryFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(RepositoryFile::class);
    }

    /**
     * The modules (folders that group the files) found by the last code analysis.
     *
     * @return HasMany<Module, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    /**
     * The dependency graph between modules: which module imports which.
     *
     * @return HasMany<ModuleDependency, $this>
     */
    public function moduleDependencies(): HasMany
    {
        return $this->hasMany(ModuleDependency::class);
    }

    /**
     * The commits imported from the clone's git history (ImportCommits).
     *
     * @return HasMany<Commit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(Commit::class);
    }

    /**
     * The architecture diagrams and the „Cum funcționează” flows (Faza 7, BuildDiagrams).
     * chaperone() gives every loaded diagram this repository back, without another query.
     *
     * @return HasMany<Diagram, $this>
     */
    public function diagrams(): HasMany
    {
        return $this->hasMany(Diagram::class)->chaperone();
    }

    /**
     * The pieces of code the assistant searches and cites, written by the last IndexCode step (Faza 8).
     *
     * @return HasMany<CodeChunk, $this>
     */
    public function codeChunks(): HasMany
    {
        return $this->hasMany(CodeChunk::class);
    }

    /**
     * Whether a pipeline run for this repository has started and not finished yet.
     * A second run must wait: two clones in the same folder would break each other.
     */
    public function isBeingProcessed(): bool
    {
        return $this->processingRuns()->where('status', ProcessingRunStatus::Running)->exists();
    }

    /**
     * The folder of the clone, relative to the "local" disk (storage/app/private).
     */
    public function cloneDirectory(): string
    {
        return "repositories/{$this->id}";
    }

    /**
     * The absolute path of the clone, the one git and the analyzer work in.
     */
    public function clonePath(): string
    {
        return Storage::disk('local')->path($this->cloneDirectory());
    }
}
