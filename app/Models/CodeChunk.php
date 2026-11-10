<?php

namespace App\Models;

use Database\Factories\CodeChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of a file that the assistant can find and cite (Faza 8): a class or a function
 * (symbol_name, e.g. "LoginController.store") or a window of lines without a symbol of its own,
 * with the exact lines it covers. App\Search\CodeIndexer writes them, App\Search\CodeSearch finds them.
 */
#[Fillable(['repository_file_id', 'start_line', 'end_line', 'symbol_name', 'content'])]
#[WithoutTimestamps]
class CodeChunk extends Model
{
    /** @use HasFactory<CodeChunkFactory> */
    use HasFactory;

    /**
     * The repository this chunk belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The file the chunk was cut from.
     *
     * @return BelongsTo<RepositoryFile, $this>
     */
    public function repositoryFile(): BelongsTo
    {
        return $this->belongsTo(RepositoryFile::class);
    }

    /**
     * Only the chunks of these files and folders: a path ending in "/" is a folder (everything under it),
     * any other path is one file (see App\Search\MentionedPaths).
     *
     * @param  Builder<CodeChunk>  $query
     * @param  list<string>  $paths
     */
    #[Scope]
    protected function inPaths(Builder $query, array $paths): void
    {
        $query->whereHas('repositoryFile', function (Builder $files) use ($paths): void {
            $files->where(function (Builder $files) use ($paths): void {
                foreach ($paths as $path) {
                    if (str_ends_with($path, '/')) {
                        $files->orWhereRaw("path like ? escape '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $path).'%']);
                    } else {
                        $files->orWhere('path', $path);
                    }
                }
            });
        });
    }
}
