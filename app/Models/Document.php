<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One page of the generated documentation (Faza 6): the overview, the generated README or a module page.
 * Pages are found by their slug ("overview", "readme", "app-http", ...), unique per repository.
 *
 * For a module page, title is the module's path ("app/Http", "/" for the root folder).
 * Every change of the Markdown is also kept in DocumentVersion (the time travel of Faza 9).
 *
 * repository_id is not fillable: the code sets it with $document->repository()->associate().
 */
#[Fillable([
    'module_id', 'slug', 'title', 'markdown', 'source_sha', 'input_hash', 'writer', 'model', 'ai_driver',
    'input_tokens', 'output_tokens', 'context_characters', 'cost_usd_equivalent', 'generated_at', 'is_stale', 'stale_reason',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * The slug of the overview page („Despre proiect”).
     */
    public const OVERVIEW = 'overview';

    /**
     * The slug of the generated README (also downloadable as README.md).
     */
    public const README = 'readme';

    /**
     * The value of "writer" for a page written by Claude.
     */
    public const WRITER_AI = 'ai';

    /**
     * The value of "writer" for a page written from the code analysis, without AI.
     */
    public const WRITER_TEMPLATE = 'template';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'is_stale' => 'boolean',
            'cost_usd_equivalent' => 'decimal:6',
        ];
    }

    /**
     * The repository this page documents.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The module of a module page; null for the overview and the README.
     *
     * @return BelongsTo<Module, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Every version of the page's Markdown, oldest first.
     *
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->oldest()->oldest('id');
    }

    /**
     * Whether this is a module page (and not the overview or the README).
     */
    public function isModulePage(): bool
    {
        return ! in_array($this->slug, [self::OVERVIEW, self::README], true);
    }
}
