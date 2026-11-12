<?php

namespace App\Diagrams;

use App\Models\CodeSymbol;
use App\Models\FileImport;
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
        'CSS', 'SCSS', 'Less', 'SQL', 'Shell', 'Perl', 'Ruby', 'Rust', 'C', 'C++', 'C#', 'Kotlin', 'Swift',
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

    /**
     * The code files of a small or flat repository, for the components diagram at file level: the files
     * with code that sit in a module of the application or in the root folder (tests, configuration,
     * documentation, translations and runtime files stay out, as for modules).
     *
     * Each file stands in a Module object that is never saved: its id and path are the file's, and its
     * line_count is the file's lines, so the facts of the diagrams (ArchitectureFacts) work on files too.
     *
     * @return Collection<int, Module>
     */
    public function applicationFiles(Repository $repository): Collection
    {
        $moduleIds = $repository->modules()->get(['id', 'path'])
            ->filter(fn (Module $module): bool => in_array(LayerClassifier::exclusionReason($module->path), [null, 'root'], true))
            ->pluck('id');

        return RepositoryFile::query()
            ->whereBelongsTo($repository)
            ->whereIn('language', self::CODE_LANGUAGES)
            ->whereIn('module_id', $moduleIds)
            ->orderBy('path')
            ->get(['id', 'path', 'lines'])
            ->map(fn (RepositoryFile $file): Module => (new Module)->forceFill(['id' => $file->id, 'path' => $file->path, 'name' => $file->path, 'line_count' => $file->lines]))
            ->values();
    }

    /**
     * The imports between the given files (see applicationFiles()), strongest first: one entry for each
     * pair of different files, its weight is the number of imports of the first that point to the second.
     *
     * @param  Collection<int, Module>  $files
     * @return list<array{from: int, to: int, weight: int}>
     */
    public function dependenciesBetweenFiles(Repository $repository, Collection $files): array
    {
        $paths = $files->pluck('path', 'id');
        $weights = [];

        FileImport::query()
            ->whereBelongsTo($repository)
            ->whereIn('repository_file_id', $paths->keys())
            ->whereIn('target_file_id', $paths->keys())
            ->whereColumn('repository_file_id', '!=', 'target_file_id')
            ->get(['repository_file_id', 'target_file_id'])
            ->each(function (FileImport $import) use (&$weights): void {
                $key = $import->repository_file_id.':'.$import->target_file_id;
                $weights[$key] = ($weights[$key] ?? 0) + 1;
            });

        $dependencies = [];

        foreach ($weights as $key => $weight) {
            [$from, $to] = array_map(intval(...), explode(':', $key));
            $dependencies[] = ['from' => $from, 'to' => $to, 'weight' => $weight];
        }

        usort($dependencies, fn (array $a, array $b): int => [$b['weight'], $paths[$a['from']], $paths[$a['to']]]
            <=> [$a['weight'], $paths[$b['from']], $paths[$b['to']]]);

        return $dependencies;
    }

    /**
     * The main symbols of each file, to name it on the diagram: types first (classes, structs, traits,
     * enums, interfaces), then functions, then methods; the biggest first. Any function counts, because
     * in a flat project, or in shell and Rust, functions are written in lowercase.
     *
     * @param  Collection<int, Module>  $files  the files of applicationFiles()
     * @return array<int, list<string>> file id => symbol names
     */
    public function mainSymbolNames(Collection $files, int $limit): array
    {
        $rank = ['class' => 0, 'struct' => 0, 'trait' => 0, 'enum' => 0, 'interface' => 0, 'record' => 0, 'type' => 0, 'function' => 1, 'method' => 2];

        return CodeSymbol::query()
            ->whereIn('repository_file_id', $files->pluck('id'))
            ->whereIn('kind', array_keys($rank))
            ->get(['repository_file_id', 'kind', 'name', 'start_line', 'end_line'])
            ->sort(fn (CodeSymbol $a, CodeSymbol $b): int => [$rank[$a->kind], $b->end_line - $b->start_line, $a->name]
                <=> [$rank[$b->kind], $a->end_line - $a->start_line, $b->name])
            ->groupBy('repository_file_id')
            ->map(fn (Collection $symbols): array => $symbols->pluck('name')->unique()->take($limit)->values()->all())
            ->all();
    }
}
