<?php

namespace App\Models;

use Database\Factories\RepositoryFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A file of the repository that the analysis read: its language ("PHP", "TypeScript", ...,
 * or null when unknown), its number of lines and the git object id of its content (blob_sha).
 */
#[Fillable(['module_id', 'path', 'language', 'lines', 'size_bytes', 'blob_sha'])]
#[WithoutTimestamps]
class RepositoryFile extends Model
{
    /** @use HasFactory<RepositoryFileFactory> */
    use HasFactory;

    /**
     * The repository this file belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The module (folder) this file belongs to.
     *
     * @return BelongsTo<Module, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * The classes, functions, ... declared in this file.
     *
     * @return HasMany<CodeSymbol, $this>
     */
    public function symbols(): HasMany
    {
        return $this->hasMany(CodeSymbol::class);
    }

    /**
     * What this file imports.
     *
     * @return HasMany<FileImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(FileImport::class);
    }
}
