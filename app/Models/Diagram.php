<?php

namespace App\Models;

use App\Enums\DiagramType;
use Database\Factories\DiagramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One diagram of a repository, as Mermaid code (Faza 7). BuildDiagrams writes them after the
 * code analysis; the „Diagrame” tab draws them in the browser.
 *
 * "meta" depends on the type: every diagram has a title and a caption; the layers view lists the
 * excluded folders, the container view the detected technologies, a flow its explanation and
 * the source lines it is based on (see ArchitectureDiagramBuilder and FlowDiagramWriter).
 */
#[Fillable(['type', 'key', 'mermaid', 'meta', 'source_sha'])]
class Diagram extends Model
{
    /** @use HasFactory<DiagramFactory> */
    use HasFactory;

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
     * The repository this diagram was drawn for.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
