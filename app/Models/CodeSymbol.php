<?php

namespace App\Models;

use Database\Factories\CodeSymbolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A class, interface, trait, enum, function, method, ... found by tree-sitter in a file,
 * with the lines where it starts and ends.
 */
#[Fillable(['repository_file_id', 'kind', 'name', 'start_line', 'end_line'])]
#[WithoutTimestamps]
class CodeSymbol extends Model
{
    /** @use HasFactory<CodeSymbolFactory> */
    use HasFactory;

    /**
     * The repository this symbol belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The file that declares this symbol.
     *
     * @return BelongsTo<RepositoryFile, $this>
     */
    public function repositoryFile(): BelongsTo
    {
        return $this->belongsTo(RepositoryFile::class);
    }
}
