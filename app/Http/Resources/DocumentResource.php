<?php

namespace App\Http\Resources;

use App\Documentation\DocumentNavigation;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One documentation page as the frontend receives it (the DocumentPage type in TypeScript):
 * the chapter fields of DocumentNavigation plus the Markdown and who wrote it.
 * Make it with ->resolve() and the repository already loaded on the document.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * "download_url" exists only for the generated README (GET /repositories/{id}/docs/readme.md).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...DocumentNavigation::item($this->resource, $this->repository),
            'markdown' => $this->markdown,
            'writer' => $this->writer,
            'model' => $this->model,
            'ai_driver' => $this->ai_driver,
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'cost_usd_equivalent' => $this->cost_usd_equivalent === null ? null : (float) $this->cost_usd_equivalent,
            'source_sha' => $this->source_sha,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'stale_reason' => $this->stale_reason,
            'download_url' => $this->slug === Document::README
                ? route('repositories.docs.readme', $this->repository, absolute: false)
                : null,
        ];
    }
}
