<?php

namespace App\Models;

use Database\Factories\ModuleDependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An edge of the dependency graph: files of the "from" module import files of the "to" module,
 * and weight counts those imports.
 */
#[Fillable(['from_module_id', 'to_module_id', 'weight'])]
#[WithoutTimestamps]
class ModuleDependency extends Model
{
    /** @use HasFactory<ModuleDependencyFactory> */
    use HasFactory;

    /**
     * The repository this dependency belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The module that imports.
     *
     * @return BelongsTo<Module, $this>
     */
    public function fromModule(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'from_module_id');
    }

    /**
     * The module that is imported.
     *
     * @return BelongsTo<Module, $this>
     */
    public function toModule(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'to_module_id');
    }
}
