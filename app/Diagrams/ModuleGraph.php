<?php

namespace App\Diagrams;

/**
 * The graph arithmetic behind the findings of an architecture diagram: a directed graph of modules
 * (a node is a module path) and the imports between them. It is pure: no database, no framework,
 * so it can be tested on a graph made by hand.
 *
 * An edge "a → b" with weight 5 means that code of module a imports code of module b in 5 places.
 */
final class ModuleGraph
{
    /**
     * Import counts by edge: from => to => number of imports.
     *
     * @var array<string, array<string, int>>
     */
    private array $edges = [];

    /**
     * @param  list<string>  $nodes  the modules of the graph, by path
     * @param  list<array{from: string, to: string, weight: int}>  $edges  edges between two different nodes of the graph
     */
    public function __construct(private array $nodes, array $edges)
    {
        foreach ($edges as $edge) {
            if ($edge['from'] !== $edge['to'] && in_array($edge['from'], $nodes, true) && in_array($edge['to'], $nodes, true)) {
                $this->edges[$edge['from']][$edge['to']] = ($this->edges[$edge['from']][$edge['to']] ?? 0) + $edge['weight'];
            }
        }
    }

    /**
     * The modules that most other modules depend on ("the core"): at most $limit, by the number of
     * modules that import them, then by the number of imports. A module nobody imports is never listed.
     *
     * @return list<array{module: string, modules: int, imports: int}>
     */
    public function mostDependedOn(int $limit): array
    {
        $incoming = [];

        foreach ($this->edges as $targets) {
            foreach ($targets as $to => $weight) {
                $incoming[$to]['modules'] = ($incoming[$to]['modules'] ?? 0) + 1;
                $incoming[$to]['imports'] = ($incoming[$to]['imports'] ?? 0) + $weight;
            }
        }

        return $this->top($incoming, $limit);
    }

    /**
     * The modules that depend on the most others ("the coordinators"): at most $limit, by the number
     * of modules they import, then by the number of imports. A module that imports nothing is never listed.
     *
     * @return list<array{module: string, modules: int, imports: int}>
     */
    public function mostDependingOnOthers(int $limit): array
    {
        $outgoing = [];

        foreach ($this->edges as $from => $targets) {
            $outgoing[$from] = ['modules' => count($targets), 'imports' => array_sum($targets)];
        }

        return $this->top($outgoing, $limit);
    }

    /**
     * The circular dependencies: for every group of modules that depend on each other (directly or
     * through others), the shortest cycle through its first module in path order, and how many modules
     * the group has. A cycle is written in the direction of the imports: a → b → c (and back to a).
     *
     * @return list<array{modules: list<string>, size: int}>
     */
    public function cycles(): array
    {
        $cycles = [];

        foreach ($this->stronglyConnectedGroups() as $group) {
            sort($group);

            $cycles[] = ['modules' => $this->shortestCycleThrough($group[0], $group), 'size' => count($group)];
        }

        usort($cycles, fn (array $a, array $b): int => [$b['size'], $a['modules']] <=> [$a['size'], $b['modules']]);

        return $cycles;
    }

    /**
     * The imports that go against the layer order: from a module of a lower layer to a module of a
     * higher one (the layers are listed from the top). A module in a layer that is not in the order is ignored.
     *
     * @param  array<string, string>  $layerOf  module => layer key
     * @param  list<string>  $layerOrder  layer keys, from the top
     * @return list<array{from: string, to: string, imports: int}>
     */
    public function importsAgainstOrder(array $layerOf, array $layerOrder): array
    {
        $rank = array_flip($layerOrder);
        $against = [];

        foreach ($this->edges as $from => $targets) {
            foreach ($targets as $to => $weight) {
                if (isset($rank[$layerOf[$from] ?? ''], $rank[$layerOf[$to] ?? '']) && $rank[$layerOf[$to]] < $rank[$layerOf[$from]]) {
                    $against[] = ['from' => $from, 'to' => $to, 'imports' => $weight];
                }
            }
        }

        usort($against, fn (array $a, array $b): int => [$b['imports'], $a['from'], $a['to']] <=> [$a['imports'], $b['from'], $b['to']]);

        return $against;
    }

    /**
     * The modules that import nothing and are imported by nothing, in path order.
     *
     * @return list<string>
     */
    public function isolated(): array
    {
        $connected = [];

        foreach ($this->edges as $from => $targets) {
            $connected[$from] = true;

            foreach (array_keys($targets) as $to) {
                $connected[$to] = true;
            }
        }

        $isolated = array_values(array_filter($this->nodes, fn (string $node): bool => ! isset($connected[$node])));
        sort($isolated);

        return $isolated;
    }

    /**
     * The total number of imports between the modules of the graph.
     */
    public function totalImports(): int
    {
        return array_sum(array_map(array_sum(...), $this->edges));
    }

    /**
     * The best $limit entries, by modules then imports, then by path so the order is always the same.
     *
     * @param  array<string, array{modules: int, imports: int}>  $counts
     * @return list<array{module: string, modules: int, imports: int}>
     */
    private function top(array $counts, int $limit): array
    {
        $rows = [];

        foreach ($counts as $module => $count) {
            $rows[] = ['module' => (string) $module, ...$count];
        }

        usort($rows, fn (array $a, array $b): int => [$b['modules'], $b['imports'], $a['module']] <=> [$a['modules'], $a['imports'], $b['module']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * The groups of two or more modules that can all reach each other (Tarjan's algorithm).
     *
     * @return list<list<string>>
     */
    private function stronglyConnectedGroups(): array
    {
        $index = 0;
        $indexOf = [];
        $lowest = [];
        $stack = [];
        $onStack = [];
        $groups = [];

        $visit = function (string $node) use (&$visit, &$index, &$indexOf, &$lowest, &$stack, &$onStack, &$groups): void {
            $indexOf[$node] = $lowest[$node] = $index++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach (array_keys($this->edges[$node] ?? []) as $next) {
                if (! isset($indexOf[$next])) {
                    $visit($next);
                    $lowest[$node] = min($lowest[$node], $lowest[$next]);
                } elseif ($onStack[$next] ?? false) {
                    $lowest[$node] = min($lowest[$node], $indexOf[$next]);
                }
            }

            if ($lowest[$node] === $indexOf[$node]) {
                $group = [];

                do {
                    $member = array_pop($stack);
                    $onStack[$member] = false;
                    $group[] = $member;
                } while ($member !== $node);

                if (count($group) > 1) {
                    $groups[] = $group;
                }
            }
        };

        foreach ($this->nodes as $node) {
            if (! isset($indexOf[$node])) {
                $visit($node);
            }
        }

        return $groups;
    }

    /**
     * The shortest way from $start back to $start inside $group (breadth-first search), without
     * repeating $start at the end.
     *
     * @param  list<string>  $group
     * @return list<string>
     */
    private function shortestCycleThrough(string $start, array $group): array
    {
        $previous = [];
        $queue = [$start];

        while ($queue !== []) {
            $node = array_shift($queue);
            $next = array_keys($this->edges[$node] ?? []);
            sort($next);

            foreach ($next as $target) {
                if ($target === $start) {
                    $path = [$node];

                    while ($node !== $start) {
                        $node = $previous[$node];
                        $path[] = $node;
                    }

                    return array_reverse($path);
                }

                if (in_array($target, $group, true) && ! isset($previous[$target])) {
                    $previous[$target] = $node;
                    $queue[] = $target;
                }
            }
        }

        return [$start];
    }
}
