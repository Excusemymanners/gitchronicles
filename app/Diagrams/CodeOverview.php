<?php

namespace App\Diagrams;

use App\Models\CodeSymbol;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Support\Collection;

/**
 * The parts of the code analysis that the diagrams (ArchitectureDiagramBuilder) and the
 * „Cum funcționează” context (FlowDiagramWriter) both need: which modules belong to the running
 * application, the dependencies between them and the main class names of each module.
 */
final class CodeOverview
{
    /**
     * The languages that count as code; a module with none of them (only .gitignore, JSON, Markdown, ...)
     * is left out of the diagrams.
     */
    public const CODE_LANGUAGES = [
        'PHP', 'JavaScript', 'TypeScript', 'Python', 'Go', 'Java', 'Blade', 'Vue', 'Svelte', 'HTML',
        'CSS', 'SCSS', 'Less', 'SQL', 'Shell', 'Ruby', 'Rust', 'C', 'C++', 'C#', 'Kotlin', 'Swift',
    ];

    /**
     * The modules that belong to the running application (with code, not tests, configuration,
     * documentation, translations, storage or the root folder), by path, and the left-out ones grouped by reason.
     *
     * @return array{Collection<int, Module>, list<array{label: string, paths: list<string>}>}
     */
    public function applicationModules(Repository $repository): array
    {
        $modulesWithCode = RepositoryFile::query()
            ->whereBelongsTo($repository)
            ->whereIn('language', self::CODE_LANGUAGES)
            ->distinct()
            ->pluck('module_id')
            ->all();

        $included = collect();
        $excludedPaths = [];

        foreach ($repository->modules()->orderBy('path')->get() as $module) {
            $reason = LayerClassifier::exclusionReason($module->path)
                ?? (in_array($module->id, $modulesWithCode, true) ? null : 'no_code');

            if ($reason === null) {
                $included->push($module);
            } else {
                $excludedPaths[$reason][] = $module->path;
            }
        }

        $excluded = [];

        foreach (LayerClassifier::EXCLUSION_LABELS as $reason => $label) {
            if (isset($excludedPaths[$reason])) {
                $excluded[] = ['label' => $label, 'paths' => $excludedPaths[$reason]];
            }
        }

        return [$included, $excluded];
    }

    /**
     * The dependencies between the given modules, strongest first (then by the paths, so the order
     * never depends on database ids).
     *
     * @param  Collection<int, Module>  $modules
     * @return list<array{from: int, to: int, weight: int}>
     */
    public function dependenciesBetween(Repository $repository, Collection $modules): array
    {
        $paths = $modules->pluck('path', 'id');

        return $repository->moduleDependencies()
            ->whereIn('from_module_id', $paths->keys())
            ->whereIn('to_module_id', $paths->keys())
            ->get()
            ->map(fn (ModuleDependency $dependency): array => [
                'from' => $dependency->from_module_id,
                'to' => $dependency->to_module_id,
                'weight' => $dependency->weight,
            ])
            ->sort(fn (array $a, array $b): int => [$b['weight'], $paths[$a['from']], $paths[$a['to']]]
                <=> [$a['weight'], $paths[$b['from']], $paths[$b['to']]])
            ->values()
            ->all();
    }

    /**
     * The main class names of each module: classes, traits and enums first, then components
     * (functions whose name starts with a capital letter, like React components), then interfaces;
     * the biggest first in each group.
     *
     * @param  Collection<int, Module>  $modules
     * @return array<int, list<string>> module id => class names
     */
    public function mainClassNames(Collection $modules, int $limit): array
    {
        $symbols = CodeSymbol::query()
            ->join('repository_files', 'repository_files.id', '=', 'code_symbols.repository_file_id')
            ->whereIn('repository_files.module_id', $modules->pluck('id'))
            ->whereIn('code_symbols.kind', ['class', 'trait', 'enum', 'interface', 'function'])
            ->get(['repository_files.module_id', 'code_symbols.kind', 'code_symbols.name', 'code_symbols.start_line', 'code_symbols.end_line']);

        $rank = ['class' => 0, 'trait' => 0, 'enum' => 0, 'function' => 1, 'interface' => 2];

        return $symbols
            ->filter(fn (CodeSymbol $symbol): bool => $symbol->kind !== 'function' || preg_match('/\A\p{Lu}/u', $symbol->name) === 1)
            ->sort(fn (CodeSymbol $a, CodeSymbol $b): int => [$rank[$a->kind], $b->end_line - $b->start_line, $a->name]
                <=> [$rank[$b->kind], $a->end_line - $a->start_line, $b->name])
            ->groupBy('module_id')
            ->map(fn (Collection $moduleSymbols): array => $moduleSymbols->pluck('name')->unique()->take($limit)->values()->all())
            ->all();
    }
}
