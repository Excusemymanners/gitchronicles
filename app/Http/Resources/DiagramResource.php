<?php

namespace App\Http\Resources;

use App\Models\Diagram;
use App\Models\Document;
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
     * @return array{type: string, key: string, title: string, caption: string|null, explanation: string|null, mermaid: string, excluded: list<array{label: string, paths: list<string>}>, technologies: list<array{name: string, icon: string|null, source: string}>, sources: list<array{path: string, start_line: int, end_line: int, url: string|null}>, source_sha: string|null, modules: array<string, array{path: string, slug: string}>, findings: array<string, mixed>|null, evidence: list<array<string, mixed>>, interpretation: array{source: string, reading?: string, worth?: string, model?: string, reason?: string}|null}
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
            'modules' => $this->moduleLinks($meta['nodes'] ?? []),
            'findings' => $meta['findings'] ?? null,
            'evidence' => $this->evidence($meta['evidence'] ?? []),
            'interpretation' => $this->interpretation($meta['interpretation'] ?? null),
        ];
    }

    /**
     * The nodes of the diagram that have a documentation page: node id => module path and page slug.
     * A module without a page (too small, no code) is left out, so its box is not a link.
     *
     * @param  array<string, string>  $nodes  node id => module path
     * @return array<string, array{path: string, slug: string}>
     */
    private function moduleLinks(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $slugs = Document::whereBelongsTo($this->repository)
            ->whereNotNull('module_id')
            ->whereIn('title', array_values($nodes))
            ->pluck('slug', 'title');

        $links = [];

        foreach ($nodes as $nodeId => $path) {
            if ($slugs->has($path)) {
                $links[$nodeId] = ['path' => $path, 'slug' => $slugs[$path]];
            }
        }

        return $links;
    }

    /**
     * The evidence of the arrows with, for each example, the link to the importing and to the imported
     * file on GitHub at the commit the diagram was drawn from (null without the commit).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function evidence(array $rows): array
    {
        return array_map(fn (array $row): array => [
            ...$row,
            'examples' => array_map(fn (array $example): array => [
                ...$example,
                'url' => $this->fileUrl($example['from']),
                'target_url' => $this->fileUrl($example['to']),
            ], $row['examples']),
        ], $rows);
    }

    /**
     * What the page shows of the interpretation: who wrote it and the text (a composed reading has no text,
     * the page composes it from the findings). The input hash stays on the server.
     *
     * @param  array<string, mixed>|null  $interpretation
     * @return array<string, mixed>|null
     */
    private function interpretation(?array $interpretation): ?array
    {
        return $interpretation === null ? null : array_intersect_key($interpretation, array_flip(['source', 'reading', 'worth', 'model', 'reason']));
    }

    /**
     * The link to a file on GitHub at the diagram's commit.
     */
    private function fileUrl(string $path): ?string
    {
        if ($this->source_sha === null) {
            return null;
        }

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return "https://github.com/{$this->repository->owner}/{$this->repository->name}/blob/{$this->source_sha}/{$encoded}";
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
