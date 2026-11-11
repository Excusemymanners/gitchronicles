<?php

namespace App\Models;

use App\Enums\DiagramType;
use Database\Factories\DiagramVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a diagram: its Mermaid code as it was drawn from the commit "sha" (Faza 9).
 * DiagramHistory adds a version every time the code of a diagram changes; a version without code
 * (mermaid = null) says the diagram disappeared at that commit. Versions are never updated.
 *
 * A diagram is identified by its type and key, as in the diagrams table; processing_run_id is the
 * run that drew it, which the time travel uses to find the diagram at a commit (see ProcessedCommits).
 */
#[Fillable(['processing_run_id', 'type', 'key', 'sha', 'mermaid', 'meta'])]
class DiagramVersion extends Model
{
    /** @use HasFactory<DiagramVersionFactory> */
    use HasFactory;

    /**
     * Versions are never updated, so the table has no updated_at.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DiagramType::class,
            'meta' => 'array',
        ];
    }

    /**
     * The repository whose diagram this is.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
