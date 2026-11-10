<?php

namespace App\Http\Resources;

use App\Models\Diagram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One diagram as the page needs it (the Diagram type in resources/js/components/diagrams/types.ts):
 * the Mermaid code and what is shown around it. A flow's sources get their link to the exact lines
 * on GitHub, at the commit the flow was written for.
 *
 * @mixin Diagram
 */
class DiagramResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{type: string, key: string, title: string, caption: string|null, explanation: string|null, mermaid: string, excluded: list<array{label: string, paths: list<string>}>, technologies: list<array{name: string, icon: string|null, source: string}>, sources: list<array{path: string, start_line: int, end_line: int, url: string|null}>, source_sha: string|null}
     */
    public function toArray(Request $request): array
    {
        $meta = $this->meta ?? [];

        return [
            'type' => $this->type->value,
            'key' => $this->key,
            'title' => $meta['title'] ?? '',
            'caption' => $meta['caption'] ?? null,
            'explanation' => $meta['explanation'] ?? null,
            'mermaid' => $this->mermaid,
            'excluded' => $meta['excluded'] ?? [],
            'technologies' => $meta['technologies'] ?? [],
            'sources' => array_map(fn (array $source): array => [
                ...$source,
                'url' => $this->sourceUrl($source),
            ], $meta['sources'] ?? []),
            'source_sha' => $this->source_sha,
        ];
    }

    /**
     * The link to the lines on GitHub: https://github.com/{owner}/{name}/blob/{sha}/{path}#L{a}-L{b}.
     * Without the commit there is no stable link.
     *
     * @param  array{path: string, start_line: int, end_line: int}  $source
     */
    private function sourceUrl(array $source): ?string
    {
        if ($this->source_sha === null) {
            return null;
        }

        $path = implode('/', array_map(rawurlencode(...), explode('/', $source['path'])));

        return "https://github.com/{$this->repository->owner}/{$this->repository->name}/blob/{$this->source_sha}/{$path}#L{$source['start_line']}-L{$source['end_line']}";
    }
}
