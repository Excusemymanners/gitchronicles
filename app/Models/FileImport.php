<?php

namespace App\Models;

use Database\Factories\FileImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One import of a file, as written in the code ("target"). When it points to another file
 * of the same repository, target_file_id is that file; is_external marks a library.
 */
#[Fillable(['repository_file_id', 'target', 'target_file_id', 'is_external'])]
#[WithoutTimestamps]
class FileImport extends Model
{
    /** @use HasFactory<FileImportFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
        ];
    }

    /**
     * The repository this import belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The file that contains the import.
     *
     * @return BelongsTo<RepositoryFile, $this>
     */
    public function repositoryFile(): BelongsTo
    {
        return $this->belongsTo(RepositoryFile::class);
    }

    /**
     * The file of the repository the import points to, if we found it.
     *
     * @return BelongsTo<RepositoryFile, $this>
     */
    public function targetFile(): BelongsTo
    {
        return $this->belongsTo(RepositoryFile::class, 'target_file_id');
    }
}
