<?php

namespace App\Models;

use Database\Factories\ModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of the repository that groups its files, for example "app/Http" (see ModuleGrouper).
 * The root module has the path "/" and the name of the repository.
 *
 * file_count and line_count are saved by the analysis, so pages do not have to count again.
 */
#[Fillable(['path', 'name', 'file_count', 'line_count'])]
#[WithoutTimestamps]
class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use HasFactory;

    /**
     * The repository this module belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The files of this module.
     *
     * @return HasMany<RepositoryFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(RepositoryFile::class);
    }

    /**
     * The modules this module uses (its imports point to them).
     *
     * @return HasMany<ModuleDependency, $this>
     */
    public function outgoingDependencies(): HasMany
    {
        return $this->hasMany(ModuleDependency::class, 'from_module_id');
    }

    /**
     * The modules that use this module.
     *
     * @return HasMany<ModuleDependency, $this>
     */
    public function incomingDependencies(): HasMany
    {
        return $this->hasMany(ModuleDependency::class, 'to_module_id');
    }
}
