<?php

namespace App\Http\Resources;

use App\Enums\DiagramType;
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
            'reading' => $meta['reading'] ?? null,
            'source' => $meta['source'] ?? null,
            'dropped' => (int) ($meta['stats']['dropped_edges'] ?? $meta['stats']['dropped'] ?? 0),
            'main' => (bool) ($meta['main'] ?? false),
            'legend' => $meta['legend'] ?? [],
            'unconnected' => $meta['unconnected'] ?? [],
            'parts' => $this->parts($meta['parts'] ?? []),
            'steps' => $this->type === DiagramType::Overview ? $this->arrows($meta['evidence'] ?? []) : $this->steps($meta),
        ];
    }

    /**
     * The parts of the big picture, each with its modules linked to their documentation page (when they have one).
     *
     * @param  list<array<string, mixed>>  $parts
     * @return list<array<string, mixed>>
     */
    private function parts(array $parts): array
    {
        if ($parts === []) {
            return [];
        }

        $slugs = Document::whereBelongsTo($this->repository)->whereNotNull('module_id')
            ->whereIn('title', array_merge(...array_column($parts, 'modules')))->pluck('slug', 'title');

        return array_map(fn (array $part): array => [
            'id' => $part['id'],
            'label' => $part['label'],
            'does' => $part['does'],
            'kind' => $part['kind'],
            'modules' => array_map(fn (string $path): array => ['path' => $path, 'slug' => $slugs[$path] ?? null], $part['modules']),
            'outside' => $part['outside'] ?? [],
        ], $parts);
    }

    /**
     * The arrows of the big picture in drawing order (the numbered main path first), each with the places in
     * the code that back it, linked to GitHub at their line.
     *
     * @param  list<array<string, mixed>>  $arrows
     * @return list<array<string, mixed>>
     */
    private function arrows(array $arrows): array
    {
        return array_map(fn (array $arrow): array => [
            'from' => $arrow['from'],
            'to' => $arrow['to'],
            'from_label' => $arrow['from_label'],
            'to_label' => $arrow['to_label'],
            'text' => $arrow['verb'] ?? '',
            'step' => $arrow['step'] ?? null,
            'code' => array_map(fn (array $example): array => [
                'path' => $example['from'],
                'line' => $example['line'] ?? null,
                'excerpt' => $example['excerpt'] ?? null,
                'url' => $this->fileUrl($example['from'], $example['line'] ?? null),
            ], array_slice($arrow['examples'] ?? [], 0, 1)),
        ], $arrows);
    }

    /**
     * The numbered messages of a flow with the lines of code that do each step, linked to GitHub.
     *
     * @param  array<string, mixed>  $meta
     * @return list<array<string, mixed>>
     */
    private function steps(array $meta): array
    {
        $labels = array_column($meta['participants'] ?? [], 'label', 'id');

        return array_map(fn (array $step, int $index): array => [
            'from' => $step['from'],
            'to' => $step['to'],
            'from_label' => $labels[$step['from']] ?? '',
            'to_label' => $labels[$step['to']] ?? '',
            'text' => $step['text'],
            'step' => $index + 1,
            'type' => $step['type'],
            'code' => $step['code'] === null ? [] : [[
                'path' => $step['code']['path'],
                'line' => $step['code']['start_line'],
                'excerpt' => $step['does'],
                'url' => $this->sourceUrl($step['code']),
            ]],
        ], $meta['steps'] ?? [], array_keys($meta['steps'] ?? []));
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
                'url' => $this->fileUrl($example['from'], $example['line'] ?? null),
                'target_url' => ($example['to'] ?? null) === null ? null : $this->fileUrl($example['to']),
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
     * The link to a file on GitHub at the diagram's commit (at a line, when one is given).
     */
    private function fileUrl(string $path, ?int $line = null): ?string
    {
        if ($this->source_sha === null) {
            return null;
        }

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return "https://github.com/{$this->repository->owner}/{$this->repository->name}/blob/{$this->source_sha}/{$encoded}".($line === null ? '' : "#L{$line}");
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
