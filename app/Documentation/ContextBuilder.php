<?php

namespace App\Documentation;

use App\Analysis\ReadmeDescription;
use App\Models\CodeSymbol;
use App\Models\FileImport;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\RepositoryFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds what a documentation writer receives: the context of one module (ModuleContext) or of the
 * whole project (OverviewContext). It only reads the analysis tables (Faza 5) and files of the clone.
 *
 * The context is deterministic: the same code gives the same context, byte for byte (every list has an
 * explicit order), so its sha256 (the input hash) changes only when something the writer sees changes.
 *
 * The source code sent for a module is limited to config('gitchronicle.docs.module_context_characters')
 * characters. The files are taken in order of importance:
 *
 * 1. imported by the most files (other code depends on them);
 * 2. then the most classes and functions;
 * 3. then the most lines; then by path.
 *
 * A file that fits is sent whole. The first file that does not fit is sent only with its beginning
 * (when at least MIN_EXCERPT_CHARACTERS still fit), and every file after it is listed as omitted,
 * with the reason. Nothing is dropped without saying so.
 */
final class ContextBuilder
{
    /**
     * At most this many files are listed by name in a module context (and in its omitted list).
     */
    public const MAX_LISTED_FILES = 200;

    /**
     * At most this many symbols (classes, functions, ...) are listed in a module context.
     */
    public const MAX_SYMBOLS = 300;

    /**
     * At most this many imports are listed in each direction (the rest are only counted).
     */
    public const MAX_IMPORTS = 60;

    /**
     * A beginning of a file shorter than this is not worth sending: the file is omitted instead.
     */
    public const MIN_EXCERPT_CHARACTERS = 1500;

    /**
     * How much of the README is sent for the overview and the generated README.
     */
    public const README_CHARACTERS = 8000;

    /**
     * How many of the strongest dependencies between modules the overview gets.
     */
    public const MAX_DEPENDENCIES = 15;

    /**
     * At most this many features are taken from the README's feature list.
     */
    public const MAX_FEATURES = 15;

    /**
     * The name under which the files without a recognized language are counted in the overview's languages.
     */
    public const OTHER_LANGUAGE = 'Other';

    /**
     * Headings of a README section that lists features: "Features", "Funcționalități", "Ce face", ...
     */
    private const FEATURE_HEADINGS = '/\A(features?|key features|what it does|funcționalități|functionalitati|caracteristici|ce face)\b/iu';

    /**
     * The context of one module page.
     */
    public function module(Module $module): ModuleContext
    {
        $repository = $module->repository;
        $clone = new CloneFiles($repository);

        $files = $module->files()->orderBy('path')->get(['id', 'module_id', 'path', 'language', 'lines']);
        $moduleFileIds = $module->files()->select('id');

        $symbols = CodeSymbol::query()
            ->whereIn('repository_file_id', $moduleFileIds)
            ->get(['repository_file_id', 'kind', 'name', 'start_line', 'end_line'])
            ->groupBy('repository_file_id');

        $outgoingImports = FileImport::query()
            ->whereIn('repository_file_id', $moduleFileIds)
            ->with('targetFile:id,module_id,path')
            ->get(['repository_file_id', 'target', 'target_file_id', 'is_external']);

        $incomingImports = FileImport::query()
            ->whereIn('target_file_id', $moduleFileIds)
            ->with('repositoryFile:id,module_id,path')
            ->get(['repository_file_id', 'target_file_id']);

        $pathOf = $files->pluck('path', 'id');
        $importedBy = $incomingImports->countBy('target_file_id');
        $rankedFiles = $this->rankByImportance($files, $importedBy, $symbols);

        [$excerpts, $omittedFiles] = $this->excerpts($clone, $rankedFiles);

        $rankedSymbols = $rankedFiles
            ->flatMap(fn (RepositoryFile $file): Collection => ($symbols[$file->id] ?? collect())
                ->sortBy([['start_line', 'asc'], ['name', 'asc']])
                ->map(fn (CodeSymbol $symbol): array => [
                    'path' => $file->path,
                    'kind' => $symbol->kind,
                    'name' => $symbol->name,
                    'start_line' => $symbol->start_line,
                    'end_line' => $symbol->end_line,
                ]))
            ->values();

        $importsOut = $outgoingImports
            ->filter(fn (FileImport $import): bool => $import->targetFile?->module_id !== $module->id)
            ->map(fn (FileImport $import): array => [
                'from' => $pathOf[$import->repository_file_id],
                'to' => $import->targetFile->path ?? $import->target,
                'external' => $import->is_external,
            ])
            ->unique(fn (array $import): string => $import['from'].'→'.$import['to'])
            ->sortBy([['from', 'asc'], ['to', 'asc']]);

        $importsIn = $incomingImports
            ->filter(fn (FileImport $import): bool => $import->repositoryFile->module_id !== $module->id)
            ->map(fn (FileImport $import): array => ['from' => $import->repositoryFile->path, 'to' => $pathOf[$import->target_file_id]])
            ->unique(fn (array $import): string => $import['from'].'→'.$import['to'])
            ->sortBy([['to', 'asc'], ['from', 'asc']]);

        $internalImports = $outgoingImports
            ->filter(fn (FileImport $import): bool => $import->targetFile?->module_id === $module->id)
            ->map(fn (FileImport $import): array => ['from' => $pathOf[$import->repository_file_id], 'to' => $import->targetFile->path])
            ->unique(fn (array $import): string => $import['from'].'→'.$import['to'])
            ->sortBy([['from', 'asc'], ['to', 'asc']]);

        return new ModuleContext(
            repository: "{$repository->owner}/{$repository->name}",
            path: $module->path,
            fileCount: $module->file_count,
            lineCount: $module->line_count,
            folderReadme: $this->folderReadme($clone, $module, $files),
            files: $files->take(self::MAX_LISTED_FILES)
                ->map(fn (RepositoryFile $file): array => ['path' => $file->path, 'language' => $file->language, 'lines' => $file->lines])
                ->values()->all(),
            unlistedFileCount: max(0, $files->count() - self::MAX_LISTED_FILES),
            symbols: $rankedSymbols->take(self::MAX_SYMBOLS)->all(),
            unlistedSymbolCount: max(0, $rankedSymbols->count() - self::MAX_SYMBOLS),
            usesModules: $this->moduleDependencies($module, 'from_module_id', 'toModule'),
            usedByModules: $this->moduleDependencies($module, 'to_module_id', 'fromModule'),
            importsOut: $importsOut->take(self::MAX_IMPORTS)->values()->all(),
            importsIn: $importsIn->take(self::MAX_IMPORTS)->values()->all(),
            internalImports: $internalImports->take(self::MAX_IMPORTS)->values()->all(),
            unlistedImportCount: collect([$importsOut, $importsIn, $internalImports])
                ->sum(fn (Collection $imports): int => max(0, $imports->count() - self::MAX_IMPORTS)),
            excerpts: $excerpts,
            omittedFiles: array_slice($omittedFiles, 0, self::MAX_LISTED_FILES),
            omittedFileCount: count($omittedFiles),
            characterBudget: $this->characterBudget(),
        );
    }

    /**
     * The context of the overview page and of the generated README.
     */
    public function overview(DocumentationPlan $plan): OverviewContext
    {
        $repository = $plan->repository;
        $clone = new CloneFiles($repository);

        $files = RepositoryFile::whereBelongsTo($repository)->orderBy('path')->get(['path', 'language', 'lines']);
        $facts = new ProjectFacts($clone, $files->pluck('path')->all());

        $languages = $files
            ->groupBy(fn (RepositoryFile $file): string => $file->language ?? self::OTHER_LANGUAGE)
            ->map(fn (Collection $group, string $name): array => ['name' => $name, 'files' => $group->count(), 'lines' => (int) $group->sum('lines')])
            ->sortBy([['lines', 'desc'], ['name', 'asc']])
            ->values();

        $dependencies = ModuleDependency::whereBelongsTo($repository)
            ->with(['fromModule:id,path', 'toModule:id,path'])
            ->get();

        return new OverviewContext(
            repository: "{$repository->owner}/{$repository->name}",
            name: $repository->name,
            readme: $this->rootReadme($clone, $files),
            fileCount: $files->count(),
            lineCount: (int) $files->sum('lines'),
            languages: $languages->all(),
            manifests: $facts->manifests,
            topFolders: $this->topFolders($files),
            modules: $this->modulesForOverview($plan),
            dependencies: $dependencies
                ->map(fn (ModuleDependency $dependency): array => [
                    'from' => $dependency->fromModule->path,
                    'to' => $dependency->toModule->path,
                    'weight' => $dependency->weight,
                ])
                ->sortBy([['weight', 'desc'], ['from', 'asc'], ['to', 'asc']])
                ->take(self::MAX_DEPENDENCIES)->values()->all(),
            dependencyCount: $dependencies->count(),
            entryPoints: $facts->entryPoints,
            commands: $facts->commands,
            requirements: $facts->requirements,
            tests: $facts->tests,
            license: $facts->license,
        );
    }

    /**
     * The files of a module, the most important first (see the class comment).
     *
     * @param  Collection<int, RepositoryFile>  $files
     * @param  Collection<int, int>  $importedBy  file id => how many imports point to it
     * @param  Collection<int, Collection<int, CodeSymbol>>  $symbols  file id => its symbols
     * @return Collection<int, RepositoryFile>
     */
    private function rankByImportance(Collection $files, Collection $importedBy, Collection $symbols): Collection
    {
        return $files
            ->sort(fn (RepositoryFile $a, RepositoryFile $b): int => [
                $importedBy[$b->id] ?? 0, isset($symbols[$b->id]) ? $symbols[$b->id]->count() : 0, $b->lines, $a->path,
            ] <=> [
                $importedBy[$a->id] ?? 0, isset($symbols[$a->id]) ? $symbols[$a->id]->count() : 0, $a->lines, $b->path,
            ])
            ->values();
    }

    /**
     * The source code of the most important files, within the character budget, and the files left out.
     *
     * @param  Collection<int, RepositoryFile>  $rankedFiles
     * @return array{0: list<array{path: string, start_line: int, end_line: int, total_lines: int, content: string}>, 1: list<array{path: string, lines: int, reason: string}>}
     */
    private function excerpts(CloneFiles $clone, Collection $rankedFiles): array
    {
        $remaining = $this->characterBudget();
        $budgetIsFull = false;
        $excerpts = [];
        $omitted = [];

        foreach ($rankedFiles as $file) {
            if ($budgetIsFull) {
                $omitted[] = ['path' => $file->path, 'lines' => $file->lines, 'reason' => 'did not fit within the character budget'];

                continue;
            }

            $text = $clone->read($file->path);

            if ($text === null) {
                $omitted[] = ['path' => $file->path, 'lines' => $file->lines, 'reason' => 'not readable as text'];

                continue;
            }

            $numberedLines = $this->numberedLines($text);
            $content = implode("\n", $numberedLines);

            if (mb_strlen($content) <= $remaining) {
                $excerpts[] = $this->excerpt($file->path, $numberedLines, count($numberedLines));
                $remaining -= mb_strlen($content);

                continue;
            }

            $budgetIsFull = true;
            $beginning = $this->linesThatFit($numberedLines, $remaining);

            if ($remaining >= self::MIN_EXCERPT_CHARACTERS && $beginning !== []) {
                $excerpts[] = $this->excerpt($file->path, $beginning, count($numberedLines));
            } else {
                $omitted[] = ['path' => $file->path, 'lines' => $file->lines, 'reason' => 'did not fit within the character budget'];
            }
        }

        return [$excerpts, $omitted];
    }

    /**
     * One excerpt: the first lines of a file (all of them when end_line = total_lines).
     *
     * @param  list<string>  $numberedLines
     * @return array{path: string, start_line: int, end_line: int, total_lines: int, content: string}
     */
    private function excerpt(string $path, array $numberedLines, int $totalLines): array
    {
        return [
            'path' => $path,
            'start_line' => 1,
            'end_line' => count($numberedLines),
            'total_lines' => $totalLines,
            'content' => implode("\n", $numberedLines),
        ];
    }

    /**
     * The lines of a text, each prefixed with its number ("12| ..."), so a writer can cite exact lines.
     *
     * @return list<string>
     */
    private function numberedLines(string $text): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));

        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_map(fn (string $line, int $index): string => ($index + 1).'| '.$line, $lines, array_keys($lines));
    }

    /**
     * The first lines whose total length (with the line breaks) fits in the given number of characters.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function linesThatFit(array $lines, int $characters): array
    {
        $taken = [];
        $used = 0;

        foreach ($lines as $line) {
            $length = mb_strlen($line) + ($taken === [] ? 0 : 1);

            if ($used + $length > $characters) {
                break;
            }

            $taken[] = $line;
            $used += $length;
        }

        return $taken;
    }

    /**
     * The dependencies of a module in one direction, the strongest first.
     *
     * @param  'from_module_id'|'to_module_id'  $column  the side this module is on
     * @param  'toModule'|'fromModule'  $otherSide  the relationship of the other module
     * @return list<array{path: string, weight: int}>
     */
    private function moduleDependencies(Module $module, string $column, string $otherSide): array
    {
        return ModuleDependency::query()
            ->where($column, $module->id)
            ->with("{$otherSide}:id,path")
            ->get()
            ->map(fn (ModuleDependency $dependency): array => ['path' => $dependency->{$otherSide}->path, 'weight' => $dependency->weight])
            ->sortBy([['weight', 'desc'], ['path', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The first paragraph of a README that sits directly in the module's folder, or null.
     *
     * @param  Collection<int, RepositoryFile>  $files
     */
    private function folderReadme(CloneFiles $clone, Module $module, Collection $files): ?string
    {
        $folder = $module->path === '/' ? '' : $module->path.'/';

        $readme = $files->first(fn (RepositoryFile $file): bool => preg_match('/\A'.preg_quote($folder, '/').'readme(\.md|\.markdown|\.txt)?\z/i', $file->path) === 1);

        $text = $readme === null ? null : $clone->read($readme->path);

        return $text === null ? null : ReadmeDescription::fromMarkdown($text);
    }

    /**
     * The README of the root folder: its first paragraph, the items of its feature list (under a heading
     * like "Features" or "Funcționalități") and its beginning, for the writers.
     *
     * @param  Collection<int, RepositoryFile>  $files
     * @return array{path: string, description: string|null, features: list<string>, excerpt: string, truncated: bool}|null
     */
    private function rootReadme(CloneFiles $clone, Collection $files): ?array
    {
        $readme = $files->first(fn (RepositoryFile $file): bool => preg_match('/\Areadme(\.md|\.markdown|\.txt|\.rst)?\z/i', $file->path) === 1);
        $text = $readme === null ? null : $clone->read($readme->path);

        if ($text === null) {
            return null;
        }

        return [
            'path' => $readme->path,
            'description' => ReadmeDescription::fromMarkdown($text),
            'features' => $this->readmeFeatures($text),
            'excerpt' => Str::limit($text, self::README_CHARACTERS, ''),
            'truncated' => mb_strlen($text) > self::README_CHARACTERS,
        ];
    }

    /**
     * The list items under the first README heading that announces features.
     *
     * @return list<string>
     */
    private function readmeFeatures(string $markdown): array
    {
        $features = [];
        $inFeatureSection = false;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/\A#{1,6}\s+(.+?)\s*#*\s*\z/u', $line, $heading) === 1) {
                if ($features !== []) {
                    break;
                }

                $inFeatureSection = preg_match(self::FEATURE_HEADINGS, trim($heading[1], " \t*_")) === 1;

                continue;
            }

            if ($inFeatureSection && preg_match('/\A\s{0,3}(?:[-*+]|\d+[.)])\s+(.+)\z/u', $line, $item) === 1) {
                $features[] = Str::limit(trim($item[1]), 300, '…');
            }
        }

        return array_slice($features, 0, self::MAX_FEATURES);
    }

    /**
     * The folders of the root folder ("/" for the files directly in it), with their size and main languages.
     *
     * @param  Collection<int, RepositoryFile>  $files
     * @return list<array{path: string, files: int, lines: int, languages: list<string>}>
     */
    private function topFolders(Collection $files): array
    {
        return $files
            ->groupBy(fn (RepositoryFile $file): string => str_contains($file->path, '/') ? Str::before($file->path, '/') : '/')
            ->map(fn (Collection $group, string $folder): array => [
                'path' => $folder,
                'files' => $group->count(),
                'lines' => (int) $group->sum('lines'),
                'languages' => $group->whereNotNull('language')
                    ->groupBy('language')
                    ->map(fn (Collection $byLanguage): int => (int) $byLanguage->sum('lines'))
                    ->sortKeys()->sortDesc()->keys()->take(3)->values()->all(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Every module, with the path of its documentation page or the reason it has none.
     *
     * @return list<array{path: string, files: int, lines: int, page: string|null, reason: string|null}>
     */
    private function modulesForOverview(DocumentationPlan $plan): array
    {
        $modules = [];

        foreach ($plan->modulePages as $slug => $module) {
            $modules[] = [
                'path' => $module->path,
                'files' => $module->file_count,
                'lines' => $module->line_count,
                'page' => route('repositories.docs', ['repository' => $plan->repository, 'slug' => $slug], absolute: false),
                'reason' => null,
            ];
        }

        foreach ($plan->modulesWithoutPage as ['module' => $module, 'reason' => $reason]) {
            $modules[] = ['path' => $module->path, 'files' => $module->file_count, 'lines' => $module->line_count, 'page' => null, 'reason' => $reason];
        }

        usort($modules, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $modules;
    }

    /**
     * The most characters of source code one module page may send (config/gitchronicle.php).
     */
    private function characterBudget(): int
    {
        return (int) config('gitchronicle.docs.module_context_characters');
    }
}
