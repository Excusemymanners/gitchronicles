<?php

namespace App\Http\Resources;

use App\Git\HistoryStatistics;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace of a repository (the Workspace type in TypeScript), sent with every page of the repository:
 *
 * - "chapters": the table of contents on the left, in groups (Documentație, Arhitectură, Module, Istoric).
 *   A chapter appears only when its data exists, and a group without chapters is left out.
 * - "elements": the cards of the „Elemente” panel on the right; each one is null until its data exists.
 * - "assistant": whether the code is indexed for questions, and up to three starter questions about real modules.
 *
 * The diagrams and code_chunks tables come with later phases, so every query on them first
 * checks that the table exists. The git history is counted by HistoryStatistics, the same service as the
 * „Istoric” tab, so the panel and the tab always show the same numbers (lock files and libraries left out).
 * The number of queries is fixed, whatever the size of the repository: nothing is queried once per module.
 *
 * @mixin Repository
 */
class WorkspaceResource extends JsonResource
{
    /**
     * The chapters of the „Arhitectură” group: one per diagram type, in reading order (Faza 7).
     *
     * @var array<string, string>
     */
    public const DIAGRAM_CHAPTERS = [
        'flow' => 'Cum funcționează',
        'layers' => 'Straturi',
        'context' => 'Context (C4)',
        'container' => 'Containere (C4)',
        'component' => 'Componente (C4)',
    ];

    /**
     * Which diagram the „Arhitectură” card previews: the first type that exists, in this order.
     *
     * @var array<string, string>
     */
    public const PREVIEWED_DIAGRAMS = [
        'layers' => 'Arhitectura pe straturi',
        'container' => 'Containerele (C4)',
        'component' => 'Componentele (C4)',
        'context' => 'Contextul (C4)',
    ];

    /**
     * How many files the „Zone fierbinți” card lists.
     */
    public const SHOWN_HOTSPOTS = 5;

    /**
     * Transform the resource into an array.
     *
     * @return array{chapters: list<array<string, mixed>>, elements: array<string, mixed>, assistant: array{is_indexed: bool, starters: list<string>}}
     */
    public function toArray(Request $request): array
    {
        $modules = $this->modules()
            ->orderBy('path')
            ->get(['id', 'path', 'file_count', 'line_count']);

        $documents = $this->generatedDocuments();
        $diagramCounts = $this->diagramCountsByType();
        $statistics = app(HistoryStatistics::class);
        $activity = $statistics->activity($this->resource);
        $hotspots = $activity === null ? [] : $statistics->hotspots($this->resource, self::SHOWN_HOTSPOTS);

        $groups = [
            $this->group('docs', 'Documentație', $this->documentationChapters($documents)),
            $this->group('architecture', 'Arhitectură', $this->architectureChapters($diagramCounts)),
            $this->group('modules', 'Module', count: $modules->count(), folders: $this->moduleFolders($modules, $documents)),
            $this->group('history', 'Istoric', $this->historyChapters($activity, $hotspots)),
        ];

        return [
            'chapters' => array_values(array_filter($groups, fn (array $group): bool => $group['chapters'] !== [] || $group['folders'] !== [])),
            'elements' => [
                'architecture' => $this->architectureElement($diagramCounts),
                'video' => null,
                'slides' => null,
                'activity' => $activity === null ? null : [
                    ...$activity,
                    'href' => $this->pageUrl('repositories.timeline', 'commits'),
                ],
                'hotspots' => $hotspots === [] ? null : [
                    'files' => $hotspots,
                    'href' => $this->pageUrl('repositories.timeline', 'hotspots'),
                ],
            ],
            'assistant' => [
                'is_indexed' => $this->isCodeIndexed(),
                'starters' => $this->starterQuestions($modules),
            ],
        ];
    }

    /**
     * One group of the table of contents. Only the „Module” group has folders, and a count next to its title.
     *
     * @param  list<array{label: string, href: string, count: int|null}>  $chapters
     * @param  list<array<string, mixed>>  $folders
     * @return array{key: string, title: string, count: int|null, chapters: list<array{label: string, href: string, count: int|null}>, folders: list<array<string, mixed>>}
     */
    private function group(string $key, string $title, array $chapters = [], ?int $count = null, array $folders = []): array
    {
        return ['key' => $key, 'title' => $title, 'count' => $count, 'chapters' => $chapters, 'folders' => $folders];
    }

    /**
     * One chapter of the table of contents: a page, or a section of a page (the part after "#").
     *
     * @return array{label: string, href: string, count: int|null}
     */
    private function chapter(string $label, string $href, ?int $count = null): array
    {
        return ['label' => $label, 'href' => $href, 'count' => $count];
    }

    /**
     * The address of a page of this repository, relative to the site (so it works on any host),
     * optionally with the anchor of a section, for example /repositories/1/diagrams#layers.
     */
    private function pageUrl(string $routeName, ?string $section = null): string
    {
        $url = route($routeName, $this->resource, absolute: false);

        return $section === null ? $url : "{$url}#{$section}";
    }

    /**
     * The page of one generated document: /repositories/{id}/docs/{slug}.
     */
    private function documentUrl(string $slug): string
    {
        return route('repositories.docs', ['repository' => $this->resource, 'slug' => $slug], absolute: false);
    }

    /**
     * „Prezentare generală” is always there; „Despre proiect” (the overview page, opened at /docs) and
     * „README generat” only after the docs stage wrote them (Faza 6).
     *
     * @param  Collection<int, object{module_id: int|null, slug: string}>  $documents
     * @return list<array{label: string, href: string, count: int|null}>
     */
    private function documentationChapters(Collection $documents): array
    {
        $chapters = [$this->chapter('Prezentare generală', $this->pageUrl('repositories.show'))];

        if ($documents->contains('slug', 'overview')) {
            $chapters[] = $this->chapter('Despre proiect', $this->pageUrl('repositories.docs'));
        }

        if ($documents->contains('slug', 'readme')) {
            $chapters[] = $this->chapter('README generat', $this->documentUrl('readme'));
        }

        return $chapters;
    }

    /**
     * One chapter per diagram type that exists, in the order of DIAGRAM_CHAPTERS. Each one is a section
     * of the „Diagrame” page; a type with several diagrams (the flows) shows how many.
     *
     * @param  Collection<string, int>  $diagramCounts
     * @return list<array{label: string, href: string, count: int|null}>
     */
    private function architectureChapters(Collection $diagramCounts): array
    {
        $chapters = [];

        foreach (self::DIAGRAM_CHAPTERS as $type => $label) {
            $count = (int) $diagramCounts->get($type, 0);

            if ($count > 0) {
                $chapters[] = $this->chapter($label, $this->pageUrl('repositories.diagrams', $type), $count > 1 ? $count : null);
            }
        }

        return $chapters;
    }

    /**
     * The modules grouped by their top folder („app/Http” is in „app”), like a file explorer:
     * the folders in alphabetical order, then the tool folders that start with a dot (.github),
     * then the files in the root of the project („/”).
     *
     * A module opens its documentation page when the docs stage wrote one (Faza 6),
     * otherwise its row in „Structura codului” on the „Prezentare” page.
     *
     * @param  Collection<int, Module>  $modules
     * @param  Collection<int, object{module_id: int|null, slug: string}>  $documents
     * @return list<array{name: string, file_count: int, modules: list<array{id: int, path: string, label: string, file_count: int, href: string}>}>
     */
    private function moduleFolders(Collection $modules, Collection $documents): array
    {
        $documentSlugs = $documents->whereNotNull('module_id')->pluck('slug', 'module_id');

        return $modules
            ->groupBy(fn (Module $module): string => $module->path === '/' ? '/' : explode('/', $module->path)[0])
            ->sortKeysUsing(fn (string $first, string $second): int => $this->folderOrder($first) <=> $this->folderOrder($second))
            ->map(fn (Collection $folderModules, string $folder): array => [
                'name' => $folder,
                'file_count' => (int) $folderModules->sum('file_count'),
                'modules' => $folderModules->map(fn (Module $module): array => [
                    'id' => $module->id,
                    'path' => $module->path,
                    'label' => $module->path === $folder ? $folder : substr($module->path, strlen($folder) + 1),
                    'file_count' => $module->file_count,
                    'href' => $documentSlugs->has($module->id)
                        ? $this->documentUrl($documentSlugs->get($module->id))
                        : $this->pageUrl('repositories.show', "modul-{$module->id}"),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The sort key of a top folder: ordinary folders first, then the ones starting with a dot, then the root.
     *
     * @return array{int, string}
     */
    private function folderOrder(string $folder): array
    {
        $rank = match (true) {
            $folder === '/' => 2,
            str_starts_with($folder, '.') => 1,
            default => 0,
        };

        return [$rank, strtolower($folder)];
    }

    /**
     * „Commit-uri”, „Zone fierbinți” and „Contribuitori”: sections of the „Istoric” page, once the commits are imported.
     *
     * @param  array{commit_count: int, contributor_count: int}|null  $activity
     * @param  list<array<string, mixed>>  $hotspots
     * @return list<array{label: string, href: string, count: int|null}>
     */
    private function historyChapters(?array $activity, array $hotspots): array
    {
        if ($activity === null) {
            return [];
        }

        return array_values(array_filter([
            $this->chapter('Commit-uri', $this->pageUrl('repositories.timeline', 'commits'), $activity['commit_count']),
            $hotspots === [] ? null : $this->chapter('Zone fierbinți', $this->pageUrl('repositories.timeline', 'hotspots')),
            $this->chapter('Contribuitori', $this->pageUrl('repositories.timeline', 'contributors'), $activity['contributor_count']),
        ]));
    }

    /**
     * The „Arhitectură” card: the first diagram that exists from PREVIEWED_DIAGRAMS, with its Mermaid code.
     *
     * @param  Collection<string, int>  $diagramCounts
     * @return array{title: string, type: string, mermaid: string, href: string}|null
     */
    private function architectureElement(Collection $diagramCounts): ?array
    {
        $type = collect(array_keys(self::PREVIEWED_DIAGRAMS))->first(fn (string $type): bool => $diagramCounts->has($type));

        if ($type === null) {
            return null;
        }

        return [
            'title' => self::PREVIEWED_DIAGRAMS[$type],
            'type' => $type,
            'mermaid' => (string) DB::table('diagrams')
                ->where('repository_id', $this->id)
                ->where('type', $type)
                ->orderBy('key')
                ->value('mermaid'),
            'href' => $this->pageUrl('repositories.diagrams', $type),
        ];
    }

    /**
     * The generated documentation pages (Faza 6): which slugs exist and which module each one describes.
     *
     * @return Collection<int, object{module_id: int|null, slug: string}>
     */
    private function generatedDocuments(): Collection
    {
        return DB::table('documents')
            ->where('repository_id', $this->id)
            ->get(['module_id', 'slug']);
    }

    /**
     * How many diagrams of each type exist (Faza 7), for example ['layers' => 1, 'flow' => 3].
     *
     * @return Collection<string, int>
     */
    private function diagramCountsByType(): Collection
    {
        if (! Schema::hasTable('diagrams')) {
            return collect();
        }

        return DB::table('diagrams')
            ->where('repository_id', $this->id)
            ->groupBy('type')
            ->selectRaw('type, count(*) as diagram_count')
            ->pluck('diagram_count', 'type')
            ->map(fn (mixed $count): int => (int) $count);
    }

    /**
     * Whether the code was split into searchable chunks for the assistant (Faza 8).
     */
    private function isCodeIndexed(): bool
    {
        return Schema::hasTable('code_chunks') && DB::table('code_chunks')->where('repository_id', $this->id)->exists();
    }

    /**
     * Up to three questions about real modules, for the chips above the assistant's field:
     *
     * 1. the module the others use the most (the biggest sum of incoming import weights);
     * 2. the strongest dependency between two modules;
     * 3. the biggest module (in lines) not asked about yet, other than the root.
     *
     * Test folders are skipped: someone new to the project asks first about the code itself.
     *
     * @param  Collection<int, Module>  $modules
     * @return list<string>
     */
    private function starterQuestions(Collection $modules): array
    {
        $codeModules = $modules->reject(fn (Module $module): bool => $this->isTestModule($module->path))->keyBy('id');

        $dependencies = $this->moduleDependencies()
            ->get(['from_module_id', 'to_module_id', 'weight'])
            ->filter(fn (ModuleDependency $dependency): bool => $codeModules->has($dependency->from_module_id) && $codeModules->has($dependency->to_module_id));

        $questions = [];
        $askedPaths = [];

        $mostUsedModule = $dependencies
            ->groupBy('to_module_id')
            ->map(fn (Collection $incoming, int $moduleId): array => [
                'path' => $codeModules[$moduleId]->path,
                'weight' => (int) $incoming->sum('weight'),
            ])
            ->sortBy([['weight', 'desc'], ['path', 'asc']])
            ->first();

        if ($mostUsedModule !== null) {
            $questions[] = "Ce face modulul {$mostUsedModule['path']}?";
            $askedPaths[] = $mostUsedModule['path'];
        }

        $strongestDependency = $dependencies
            ->map(fn (ModuleDependency $dependency): array => [
                'from' => $codeModules[$dependency->from_module_id]->path,
                'to' => $codeModules[$dependency->to_module_id]->path,
                'weight' => $dependency->weight,
            ])
            ->sortBy([['weight', 'desc'], ['from', 'asc'], ['to', 'asc']])
            ->first();

        if ($strongestDependency !== null) {
            $questions[] = "Cum folosește {$strongestDependency['from']} modulul {$strongestDependency['to']}?";
            $askedPaths = [...$askedPaths, $strongestDependency['from'], $strongestDependency['to']];
        }

        $biggestModule = $codeModules
            ->reject(fn (Module $module): bool => $module->path === '/' || in_array($module->path, $askedPaths, true))
            ->sortBy([['line_count', 'desc'], ['path', 'asc']])
            ->first();

        if ($biggestModule !== null) {
            $questions[] = "Ce conține modulul {$biggestModule->path}?";
        }

        return $questions;
    }

    /**
     * Whether a module holds tests: one of its folders is named tests, test, __tests__ or spec.
     */
    private function isTestModule(string $path): bool
    {
        return array_intersect(explode('/', strtolower($path)), ['tests', 'test', '__tests__', 'spec']) !== [];
    }
}
