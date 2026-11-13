<?php

namespace App\Diagrams;

use App\Analysis\ModuleGrouper;

/**
 * The big picture of a repository ("How <name> works"): 5–9 parts named by their role, the systems,
 * tools and stores outside the code, and labelled arrows between them, the main path numbered.
 *
 * - fallback(): the deterministic picture, when the text generator is off, fails or is rejected: the
 *   layers that have code as parts, the most used things outside the code, the links between layers and
 *   the evidence as arrows with generic but true verbs, and no numbered path;
 * - mermaid(): the Mermaid code of a map (checked by SystemMapValidator or built by fallback()), drawn
 *   top to bottom so it reads in order: parts are neutral boxes (an interface rounded), stores are
 *   cylinders, external systems are outlined and tools dashed; the arrows of the main path come first,
 *   numbered, in the accent colour and thicker. No file or class name is ever written in the picture.
 */
final class SystemMap
{
    /**
     * How many things outside the code the fallback shows at most.
     */
    private const MAX_FALLBACK_TARGETS = 4;

    /**
     * The colour and width of the main path (the light accent; the page swaps it for the dark one).
     */
    public const MAIN_PATH_STYLE = 'stroke:#0969da,stroke-width:2.5px,color:#0969da';

    /**
     * The styles of the kinds of parts (light theme; lib/mermaid.ts restyles them for each theme).
     *
     * @var array<string, string>
     */
    public const STYLES = [
        'mapPart' => 'fill:#ffffff,stroke:#818b98,color:#1f2328',
        'mapInterface' => 'fill:#ffffff,stroke:#818b98,color:#1f2328',
        'mapWorker' => 'fill:#ffffff,stroke:#818b98,color:#1f2328',
        'mapData' => 'fill:#f6f8fa,stroke:#818b98,color:#1f2328',
        'mapExternal' => 'fill:none,stroke:#59636e,stroke-width:1.5px,color:#1f2328',
        'mapTool' => 'fill:none,stroke:#59636e,stroke-dasharray:5 4,color:#1f2328',
    ];

    /**
     * The deterministic big picture built from the facts alone.
     *
     * @param  array{modules: array<string, array{layer: string, lines: int}>, links: list<array{from: string, to: string, weight: int, kinds: array<string, int>}>, externals: list<array{module: string, target: string, kind: string, count: int}>, targets: array<string, array{name: string, type: string, count: int}>}  $facts
     * @return array{title: string, reading: string, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null
     */
    public static function fallback(array $facts, string $repositoryName): ?array
    {
        $layerModules = [];

        foreach ($facts['modules'] as $path => $module) {
            $layerModules[$module['layer']][] = $path;
        }

        $nodes = [];

        foreach (LayerClassifier::LAYERS as $layer => $title) {
            if (isset($layerModules[$layer])) {
                $isRootOnly = $layerModules[$layer] === [ModuleGrouper::ROOT_MODULE];
                $nodes["layer_{$layer}"] = [
                    'id' => "layer_{$layer}",
                    'label' => $isRootOnly ? $repositoryName : $title,
                    'does' => count($layerModules[$layer]) === 1 ? '1 module' : count($layerModules[$layer]).' modules',
                    'kind' => in_array($layer, ['entry', 'ui', 'web'], true) ? 'interface' : 'service',
                    'modules' => $layerModules[$layer],
                ];
            }
        }

        // The things outside the code used the most: up to two stores, the main service and the main tool.
        $chosen = [];
        $perType = ['data' => 2, 'external' => 2, 'tool' => 2];

        foreach ($facts['targets'] as $target => $info) {
            if (($perType[$info['type']] ?? 0) > 0 && count($chosen) < min(self::MAX_FALLBACK_TARGETS, SystemMapValidator::MAX_NODES - count($nodes))) {
                $chosen[$target] = $info;
                $perType[$info['type']]--;
            }
        }

        foreach ($chosen as $target => $info) {
            $id = 'out_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($target));
            $nodes[$id] = ['id' => $id, 'label' => $info['name'], 'does' => null, 'kind' => $info['type'], 'modules' => [$target]];
        }

        $edges = [];

        foreach ($nodes as $from) {
            foreach ($nodes as $to) {
                if ($from['id'] === $to['id'] || (! in_array($from['kind'], SystemMapValidator::CODE_KINDS, true))) {
                    continue;
                }

                $backing = SystemMapValidator::backing($from, $to, $facts);

                // Container bindings only wire the code together; they say nothing about how it works.
                if ($backing !== null && array_diff(array_keys(array_merge(...array_map(fn (array $row): array => $row['kinds'] ?? [$row['kind'] => 1], $backing['rows']))), ['binding']) !== []) {
                    $edges[] = ['id' => 'e'.(count($edges) + 1), 'from' => $from['id'], 'to' => $to['id'], 'verb' => self::genericVerb($backing), 'carries' => null, 'step' => null, 'backing' => $backing];
                }
            }
        }

        $connected = array_flip(array_merge(array_column($edges, 'from'), array_column($edges, 'to')));
        $nodes = array_values(array_filter($nodes, fn (array $node): bool => isset($connected[$node['id']])));

        if (count($nodes) < 2) {
            return null;
        }

        return [
            'title' => "How {$repositoryName} works",
            'reading' => 'The parts are the layers of the code, named by their folders, with the systems and stores the code uses; an arrow means that code of one part uses the other.',
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * The Mermaid code of a map.
     *
     * @param  array{nodes: list<array{id: string, label: string, does: string|null, kind: string}>, edges: list<array{from: string, to: string, verb: string, carries: string|null, step: int|null}>}  $map
     */
    public static function mermaid(array $map): string
    {
        // Top to bottom: nine parts side by side would make a picture too wide to read on a page.
        $chart = new Flowchart('TB', ['cycleBreakingStrategy' => 'GREEDY_MODEL_ORDER']);

        foreach ($map['nodes'] as $node) {
            $label = MermaidText::label($node['label'], array_values(array_filter([$node['does']])));
            [$class, $shape] = match ($node['kind']) {
                'interface' => ['mapInterface', 'rounded'],
                'worker' => ['mapWorker', 'rect'],
                'data' => ['mapData', 'cylinder'],
                'external' => ['mapExternal', 'rect'],
                'tool' => ['mapTool', 'rect'],
                default => ['mapPart', 'rect'],
            };

            $chart->node(self::nodeId($node['id']), $label, $class, $shape);
        }

        $edges = self::orderedEdges($map['edges']);
        $mainPath = [];

        foreach ($edges as $index => $edge) {
            // Only the number and the verb on the arrow; what is handed over is in the step list beside it.
            $chart->edge(self::nodeId($edge['from']), self::nodeId($edge['to']), MermaidText::escape(($edge['step'] !== null ? "{$edge['step']} · " : '').$edge['verb'], 60));

            if ($edge['step'] !== null) {
                $mainPath[] = $index;
            }
        }

        foreach (self::STYLES as $name => $style) {
            $chart->classDef($name, $style);
        }

        $code = $chart->toMermaid();

        return $mainPath === [] ? $code : $code.'  linkStyle '.implode(',', $mainPath).' '.self::MAIN_PATH_STYLE."\n";
    }

    /**
     * The edges in drawing order: the main path first, by step, then the others as given.
     *
     * @param  list<array{step: int|null}>  $edges
     * @return list<array<string, mixed>>
     */
    public static function orderedEdges(array $edges): array
    {
        $main = array_values(array_filter($edges, fn (array $edge): bool => $edge['step'] !== null));
        usort($main, fn (array $a, array $b): int => $a['step'] <=> $b['step']);

        return [...$main, ...array_values(array_filter($edges, fn (array $edge): bool => $edge['step'] === null))];
    }

    /**
     * The Mermaid id of a part (prefixed, so no id is a Mermaid keyword such as "end").
     */
    public static function nodeId(string $id): string
    {
        return 'n_'.$id;
    }

    /**
     * A generic but true verb for an arrow of the fallback, from what backs it: the most specific kind of
     * link between two parts of code ("dispatches", "routes to", "renders", ...; "uses" for imports), or
     * the use of the thing outside the code ("runs", "calls", "reads and writes", "queues work on").
     *
     * @param  array{type: string, rows: list<array<string, mixed>>}  $backing
     */
    private static function genericVerb(array $backing): string
    {
        $counts = [];

        foreach ($backing['rows'] as $row) {
            foreach ($backing['type'] === 'links' ? $row['kinds'] : [$row['kind'] => $row['count']] as $kind => $count) {
                $counts[$kind] = ($counts[$kind] ?? 0) + $count;
            }
        }

        foreach (['dispatch', 'route', 'render', 'event', 'schedule', 'runs', 'calls', 'queues', 'stores', 'loads'] as $kind) {
            if (isset($counts[$kind])) {
                return SystemFacts::verb($kind);
            }
        }

        return 'uses';
    }
}
