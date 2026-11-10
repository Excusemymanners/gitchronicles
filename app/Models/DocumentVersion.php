<?php

namespace App\Models;

use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a documentation page: its Markdown as it was written from the commit "sha".
 * A version is added every time the page's Markdown changes and is never updated afterwards.
 */
#[Fillable(['sha', 'markdown'])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    /**
     * Versions are never updated, so the table has no updated_at.
     */
    public const UPDATED_AT = null;

    /**
     * The page this version belongs to.
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
