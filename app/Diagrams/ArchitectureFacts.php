<?php

namespace App\Diagrams;

use App\Models\Module;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The facts that give an architecture diagram its meaning, all computed from the stored analysis:
 *
 * - findings(): the core modules, the coordinators, the cycles, the imports against the layer order,
 *   the isolated modules, how much of the repository the diagram shows and where the work happens;
 * - edgeEvidence() and layerEvidence(): for every arrow, how many imports stand behind it and up to
 *   three examples (the importing file and the file it imports).
 *
 * Only codes, module paths and numbers are stored (in the diagram's meta); the interface words them,
 * in the language of the visitor. A fact that does not apply is left out, never written as zero.
 *
 * A small or flat repository gets its components diagram at file level (see CodeOverview::applicationFiles()):
 * the same facts are then computed over files, each file standing in a Module object whose id is the
 * file's id and whose path is the file's path. The findings then say "unit": "files", and the page
 * words them with "files" instead of "modules".
 */
final class ArchitectureFacts
{
    /**
     * How many modules the "core", "coordinators" and "where work happens" lists name.
     */
    public const TOP = 3;

    /**
     * How many examples one arrow keeps.
     */
    public const EXAMPLES_PER_ARROW = 3;

    /**
     * How many examples are looked at before the best three are kept.
     */
    private const CANDIDATES_PER_ARROW = 12;

    /**
     * How many arrows keep their evidence (the layers and components views draw fewer).
     */
    public const MAX_ARROWS = 40;

    /**
     * How many isolated modules and imports against the order are listed by name.
     */
    private const MAX_LISTED = 6;

    /**
     * The findings of one diagram.
     *
     * @param  Collection<int, Module>  $allModules  the modules of the running application
     * @param  Collection<int, Module>  $shown  the modules the diagram draws
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies  between all the application modules
     * @param  int  $drawnImports  the imports the diagram draws as arrows
     * @param  array<int, string>|null  $layerOf  module id => layer key, for the layers view only
     * @param  bool  $fileLevel  the "modules" are files (see the class comment)
     * @return array<string, mixed>
     */
    public function findings(Repository $repository, Collection $allModules, Collection $shown, array $dependencies, int $drawnImports, ?array $layerOf = null, bool $fileLevel = false): array
    {
        $paths = $allModules->pluck('path', 'id')->all();
        $shownIds = $shown->pluck('id')->all();
        $edges = [];

        foreach ($dependencies as $dependency) {
            if (in_array($dependency['from'], $shownIds, true) && in_array($dependency['to'], $shownIds, true)) {
                $edges[] = ['from' => $paths[$dependency['from']], 'to' => $paths[$dependency['to']], 'weight' => $dependency['weight']];
            }
        }

        $graph = new ModuleGraph($shown->pluck('path')->all(), $edges);
        $findings = [];

        if ($graph->totalImports() > 0) {
            $findings['core'] = $graph->mostDependedOn(self::TOP);
            $findings['coordinators'] = $graph->mostDependingOnOthers(self::TOP);
        }

        if ($shown->count() >= 2) {
            $findings['cycles'] = $graph->cycles();
        }

        if ($layerOf !== null) {
            $upward = $this->againstTheLayerOrder($graph, $shown, $layerOf);

            if ($upward !== null) {
                $findings['upward'] = $upward;
            }
        }

        if ($graph->isolated() !== []) {
            $findings['isolated'] = array_slice($graph->isolated(), 0, self::MAX_LISTED);
        }

        $totalImports = array_sum(array_column($dependencies, 'weight'));

        $findings['coverage'] = [
            'shown_modules' => $shown->count(),
            'total_modules' => $allModules->count(),
            'shown_lines' => (int) $shown->sum('line_count'),
            'total_lines' => (int) $allModules->sum('line_count'),
            'total_imports' => $totalImports,
            'undrawn_imports' => max(0, $totalImports - $drawnImports),
        ];

        $busiest = $this->busiestModules($repository, $shown, $fileLevel);

        if ($busiest !== []) {
            $findings['busiest'] = $busiest;
        }

        if ($fileLevel) {
            $findings['unit'] = 'files';
        }

        return $findings;
    }

    /**
     * The evidence of the arrows between modules (components view): for every drawn arrow, its
     * imports and examples.
     *
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies  all of them
     * @param  list<array{int, int}>  $drawn  the arrows drawn, as [from module id, to module id]
     * @param  Collection<int, Module>  $allModules
     * @param  array<int, string>  $nodeIds  module id => node id
     * @param  bool  $fileLevel  the "modules" are files: the only example of an arrow is the pair of files itself
     * @return list<array{from: string, to: string, from_label: string, to_label: string, imports: int, examples: list<array{from: string, to: string}>, nodes: list<string>}>
     */
    public function edgeEvidence(Repository $repository, Collection $allModules, array $dependencies, array $drawn, array $nodeIds, bool $fileLevel = false): array
    {
        $paths = $allModules->pluck('path', 'id')->all();
        $weights = [];

        foreach ($dependencies as $dependency) {
            $weights[$dependency['from'].':'.$dependency['to']] = $dependency['weight'];
        }

        $drawn = array_slice($drawn, 0, self::MAX_ARROWS);
        $examples = $fileLevel ? [] : $this->examples($repository, array_map(fn (array $pair): string => $pair[0].':'.$pair[1], $drawn));
        $evidence = [];

        foreach ($drawn as [$from, $to]) {
            $evidence[] = [
                'from' => $nodeIds[$from],
                'to' => $nodeIds[$to],
                'from_label' => $paths[$from],
                'to_label' => $paths[$to],
                'imports' => $weights["{$from}:{$to}"],
                'examples' => $fileLevel ? [['from' => $paths[$from], 'to' => $paths[$to]]] : ($examples["{$from}:{$to}"] ?? []),
                'nodes' => [$nodeIds[$from], $nodeIds[$to]],
            ];
        }

        return $evidence;
    }

    /**
     * The evidence of the arrows between layers (layers view): for every arrow, the imports between
     * the modules of the two layers and examples, and the drawn modules that take part (so the page
     * can highlight the arrow when one of them is focused).
     *
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies  all of them
     * @param  list<array{string, string}>  $layerArrows  [from layer, to layer], the arrows drawn
     * @param  array<int, string>  $layerOf  module id => layer key
     * @param  array<int, string>  $nodeIds  shown module id => node id
     * @return list<array{from: string, to: string, from_label: string, to_label: string, imports: int, examples: list<array{from: string, to: string}>, nodes: list<string>}>
     */
    public function layerEvidence(Repository $repository, array $dependencies, array $layerArrows, array $layerOf, array $nodeIds): array
    {
        $pairsOf = [];
        $imports = [];

        foreach ($dependencies as $dependency) {
            $key = $layerOf[$dependency['from']].'→'.$layerOf[$dependency['to']];
            $pairsOf[$key][] = $dependency['from'].':'.$dependency['to'];
            $imports[$key] = ($imports[$key] ?? 0) + $dependency['weight'];
        }

        $layerArrows = array_slice($layerArrows, 0, self::MAX_ARROWS);
        $wanted = [];

        foreach ($layerArrows as [$from, $to]) {
            array_push($wanted, ...($pairsOf["{$from}→{$to}"] ?? []));
        }

        $examples = $this->examples($repository, $wanted);
        $evidence = [];

        foreach ($layerArrows as [$from, $to]) {
            $moduleIds = [];
            $all = [];

            foreach ($pairsOf["{$from}→{$to}"] ?? [] as $pair) {
                [$fromId, $toId] = array_map(intval(...), explode(':', $pair));
                array_push($all, ...($examples[$pair] ?? []));

                foreach ([$fromId, $toId] as $id) {
                    if (isset($nodeIds[$id])) {
                        $moduleIds[$nodeIds[$id]] = true;
                    }
                }
            }

            usort($all, fn (array $a, array $b): int => [$this->isTestFile($a['from']), $a['from'], $a['to']] <=> [$this->isTestFile($b['from']), $b['from'], $b['to']]);

            $evidence[] = [
                'from' => "layer_{$from}",
                'to' => "layer_{$to}",
                'from_label' => LayerClassifier::LAYERS[$from],
                'to_label' => LayerClassifier::LAYERS[$to],
                'imports' => $imports["{$from}→{$to}"] ?? 0,
                'examples' => array_slice($all, 0, self::EXAMPLES_PER_ARROW),
                'nodes' => array_keys($moduleIds),
            ];
        }

        return $evidence;
    }

    /**
     * The imports that go against the layer order among the drawn modules, or null when the drawn
     * modules do not import each other across two layers of the order (the question does not apply).
     *
     * @param  Collection<int, Module>  $shown
     * @param  array<int, string>  $layerOf
     * @return array{count: int, imports: int, pairs: list<array{from: string, to: string, imports: int}>}|null
     */
    private function againstTheLayerOrder(ModuleGraph $graph, Collection $shown, array $layerOf): ?array
    {
        $order = array_values(array_filter(array_keys(LayerClassifier::LAYERS), fn (string $layer): bool => $layer !== 'other'));
        $layerByPath = $shown->mapWithKeys(fn (Module $module): array => [$module->path => $layerOf[$module->id]])->all();
        $against = $graph->importsAgainstOrder($layerByPath, $order);

        if ($against === [] && ! $this->importsAcrossLayers($graph, $layerByPath, $order)) {
            return null;
        }

        return [
            'count' => count($against),
            'imports' => array_sum(array_column($against, 'imports')),
            'pairs' => array_slice($against, 0, self::MAX_LISTED),
        ];
    }

    /**
     * Whether some module imports a module of another layer of the order.
     *
     * @param  array<string, string>  $layerByPath
     * @param  list<string>  $order
     */
    private function importsAcrossLayers(ModuleGraph $graph, array $layerByPath, array $order): bool
    {
        // Reverse the order: the same graph then reports the imports that go the other way.
        $reverse = $graph->importsAgainstOrder($layerByPath, array_reverse($order));

        return $reverse !== [];
    }

    /**
     * The shown modules changed in the most commits (the files they hold today), or nothing when the
     * repository has no stored history.
     *
     * @param  Collection<int, Module>  $shown
     * @param  bool  $fileLevel  the shown items are files: count the commits of each file itself
     * @return list<array{module: string, commits: int}>
     */
    private function busiestModules(Repository $repository, Collection $shown, bool $fileLevel = false): array
    {
        $groupColumn = $fileLevel ? 'repository_files.id' : 'repository_files.module_id';
        $counts = DB::table('commit_files')
            ->join('commits', 'commits.id', '=', 'commit_files.commit_id')
            ->join('repository_files', fn ($join) => $join->on('repository_files.path', '=', 'commit_files.path')->on('repository_files.repository_id', '=', 'commits.repository_id'))
            ->where('commits.repository_id', $repository->id)
            ->whereIn($groupColumn, $shown->pluck('id'))
            ->groupBy($groupColumn)
            ->selectRaw("{$groupColumn} as module_id, count(distinct commit_files.commit_id) as commits")
            ->pluck('commits', 'module_id');

        $paths = $shown->pluck('path', 'id');
        $rows = [];

        foreach ($counts as $moduleId => $commits) {
            $rows[] = ['module' => $paths[$moduleId], 'commits' => (int) $commits];
        }

        usort($rows, fn (array $a, array $b): int => [$b['commits'], $a['module']] <=> [$a['commits'], $b['module']]);

        return array_slice($rows, 0, self::TOP);
    }

    /**
     * Up to three examples for each wanted pair of modules: the importing file and the file it
     * imports, in path order.
     *
     * @param  list<string>  $wantedPairs  "from module id:to module id"
     * @return array<string, list<array{from: string, to: string}>>
     */
    public function examples(Repository $repository, array $wantedPairs): array
    {
        if ($wantedPairs === []) {
            return [];
        }

        $wanted = array_flip($wantedPairs);
        $fromIds = array_unique(array_map(fn (string $pair): int => (int) explode(':', $pair)[0], $wantedPairs));
        $candidates = [];

        $rows = DB::table('file_imports')
            ->join('repository_files as importer', 'importer.id', '=', 'file_imports.repository_file_id')
            ->join('repository_files as imported', 'imported.id', '=', 'file_imports.target_file_id')
            ->where('file_imports.repository_id', $repository->id)
            ->whereIn('importer.module_id', $fromIds)
            ->whereColumn('importer.module_id', '!=', 'imported.module_id')
            ->orderBy('importer.path')
            ->orderBy('imported.path')
            ->select('importer.module_id as from_module', 'imported.module_id as to_module', 'importer.path as from_path', 'imported.path as to_path')
            ->cursor();

        foreach ($rows as $row) {
            $pair = $row->from_module.':'.$row->to_module;

            if (! isset($wanted[$pair]) || count($candidates[$pair] ?? []) >= self::CANDIDATES_PER_ARROW) {
                continue;
            }

            $example = ['from' => $row->from_path, 'to' => $row->to_path];

            if (! in_array($example, $candidates[$pair] ?? [], true)) {
                $candidates[$pair][] = $example;
            }
        }

        // Code that really uses the module is a better example than a test of it.
        return array_map(function (array $pairCandidates): array {
            usort($pairCandidates, fn (array $a, array $b): int => [$this->isTestFile($a['from']), $a['from'], $a['to']] <=> [$this->isTestFile($b['from']), $b['from'], $b['to']]);

            return array_slice($pairCandidates, 0, self::EXAMPLES_PER_ARROW);
        }, $candidates);
    }

    /**
     * Whether a file looks like a test or a fixture (by its name or folder).
     */
    private function isTestFile(string $path): bool
    {
        return preg_match('~(^|/)(tests?|__\w+__|e2e|fixtures?|spec)/|\.(test|spec)\.~i', $path) === 1;
    }
}
