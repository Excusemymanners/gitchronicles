<?php

namespace App\Models;

use Database\Factories\CodeLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One connection the import lines do not show, found by App\Analysis\LinkScanner: a place in a file
 * (line + excerpt) that points to another file of the repository (target_file_id) or to something
 * outside the code (target: "tool:git", "service:github", "store:database", ...). "kind" says how
 * they are connected (see LinkScanner::KINDS).
 */
#[Fillable(['repository_id', 'repository_file_id', 'line', 'kind', 'target_file_id', 'target', 'excerpt'])]
#[WithoutTimestamps]
class CodeLink extends Model
{
    /** @use HasFactory<CodeLinkFactory> */
    use HasFactory;

    /**
     * The repository this link belongs to.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The file that contains the link.
     *
     * @return BelongsTo<RepositoryFile, $this>
     */
    public function repositoryFile(): BelongsTo
    {
        return $this->belongsTo(RepositoryFile::class);
    }

    /**
     * The file of the repository the link points to, when it points inside the code.
     *
     * @return BelongsTo<RepositoryFile, $this>
     */
    public function targetFile(): BelongsTo
    {
        return $this->belongsTo(RepositoryFile::class, 'target_file_id');
    }
}
