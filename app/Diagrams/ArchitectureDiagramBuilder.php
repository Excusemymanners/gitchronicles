<?php

namespace App\Diagrams;

use App\Enums\DiagramType;
use App\Enums\TechnologyRole;
use App\Models\Module;
use App\Models\Repository;
use Illuminate\Support\Collection;

/**
 * Draws the architecture diagrams of a repository from real data only: the code analysis
 * (modules, dependencies, classes) and the technologies found in its files (TechnologyDetector).
 * The same data always gives the same Mermaid code, and nothing is added that the data does not show.
 *
 * 1. "Layered architecture": the modules with code, grouped in layers by folder names (LayerClassifier),
 *    with the imports between them; the left-out folders are listed under the diagram.
 * 2. "Containers" (C4 level 2): the parts that run separately (the web application, the pages in the
 *    browser, a queue worker, the database, ...) with their technology logos, inside the system boundary,
 *    and the external services the code talks to.
 * 3. "Components" (C4 level 3): the 15 biggest modules with their main classes and the strongest
 *    25 dependencies; the other modules are counted in one "+ N more modules" box.
 * 4. "Context" (C4 level 1): who uses the system and which external systems it talks to.
 *
 * A view without data is not drawn at all (a repository with only a README gets no diagrams).
 *
 * Every label inside the Mermaid code, and each diagram's title and caption, is in the content language
 * (English, see App\Support\ContentLanguage).
 */
final class ArchitectureDiagramBuilder
{
    /**
     * The layers view shows at most this many modules (the biggest); the others are counted per layer.
     */
    public const MAX_LAYER_MODULES = 30;

    /**
     * One layer shows at most this many modules, so a layer stays one readable row.
     */
    public const MAX_MODULES_PER_LAYER = 6;

    /**
     * Folder names too generic to name a component: "packages/core/src" is called "core".
     */
    private const GENERIC_FOLDERS = ['src', 'lib', 'source', 'app', 'main', 'internal', 'pkg'];

    /**
     * Inside a layer, the boxes are drawn in rows of this many.
     */
    public const GRID_COLUMNS = 3;

    /**
     * The ELK options of the layers view: boxes placed simply under each other (no staircase),
     * and when imports go both up and down, the layers keep the order they are written in.
     */
    private const LAYERED_ELK_OPTIONS = ['nodePlacementStrategy' => 'SIMPLE', 'cycleBreakingStrategy' => 'GREEDY_MODEL_ORDER'];

    /**
     * The component view shows the biggest this many modules.
     */
    public const MAX_COMPONENTS = 15;

    /**
     * A repository with fewer modules with code than this gets its components diagram at file level.
     */
    public const MIN_MODULES_FOR_MODULE_COMPONENTS = 3;

    /**
     * The file-level components diagram shows the biggest this many files.
     */
    public const MAX_COMPONENT_FILES = 20;

    /**
     * The component view draws the strongest this many dependencies.
     */
    public const MAX_COMPONENT_EDGES = 25;

    /**
     * How many class names a component shows.
     */
    public const MAX_CLASS_NAMES = 3;

    /**
     * The container view draws at most this many projects (folders with a manifest).
     */
    public const MAX_PROJECTS = 6;

    /**
     * The style of the nodes of each layer: the same white box, with a border of the layer's color.
     * The colors are mid tones, readable on a light and on a dark page.
     */
    private const LAYER_STYLES = [
        'entry' => 'stroke:#8b5cf6,stroke-width:1.5px',
        'ui' => 'stroke:#0ea5e9,stroke-width:1.5px',
        'web' => 'stroke:#6366f1,stroke-width:1.5px',
        'logic' => 'stroke:#10b981,stroke-width:1.5px',
        'data' => 'stroke:#f59e0b,stroke-width:1.5px',
        'other' => 'stroke:#9ca3af,stroke-width:1.5px',
    ];

    /**
     * The C4 colors (light theme; the page swaps them for darker variants in the dark theme):
     * dark blue for people and containers, blue for components, grey for external systems,
     * a dashed line for the system boundary. A container with a logo is a light tile with the
     * logo on it, so black logos (GitHub, Next.js) stay visible in both themes.
     */
    private const C4_STYLES = [
        'person' => 'fill:#08427b,stroke:#073b6f,color:#ffffff',
        'system' => 'fill:#1e3a8a,stroke:#1e3a8a,color:#ffffff',
        'container' => 'fill:#e8f1fb',
        'containerBox' => 'fill:#1e3a8a,stroke:#1e3a8a,color:#ffffff',
        'component' => 'fill:#2563eb,stroke:#1d4ed8,color:#ffffff',
        'external' => 'fill:#e5e7eb',
        'externalBox' => 'fill:#e5e7eb,stroke:#9ca3af,color:#111827',
        'more' => 'fill:none,stroke:#9ca3af,stroke-dasharray:4 3,color:#6b7280',
        'boundary' => 'fill:none,stroke:#6b7280,stroke-dasharray:6 4',
    ];

    /**
     * Create a new builder.
     */
    public function __construct(
        private TechnologyDetector $detector,
        private CodeOverview $overview,
        private ArchitectureFacts $facts,
    ) {}

    /**
     * Every architecture diagram that the repository's data supports.
     *
     * @return list<array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}>
     */
    public function build(Repository $repository): array
    {
        [$modules, $excluded] = $this->overview->applicationModules($repository);
        $dependencies = $this->overview->dependenciesBetween($repository, $modules);
        $report = $this->detector->detect($repository);

        return array_values(array_filter([
            $this->layersView($repository, $modules, $dependencies, $excluded),
            $this->containerView($repository, $report),
            $this->componentsDiagram($repository, $modules, $dependencies),
            $this->contextView($repository, $report),
        ]));
    }

    /**
     * "Layered architecture": one group per layer, from the entry points down to the data, with the
     * biggest modules of each layer as a grid of boxes, and one arrow from a layer to another when
     * modules of the first import code from the second (no numbers: the details are in "Components").
     *
     * @param  Collection<int, Module>  $modules
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies
     * @param  list<array{label: string, paths: list<string>}>  $excluded
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function layersView(Repository $repository, Collection $modules, array $dependencies, array $excluded): ?array
    {
        if ($modules->isEmpty()) {
            return null;
        }

        $layerOf = $modules->mapWithKeys(fn (Module $module): array => [$module->id => LayerClassifier::layerOf($module->path)]);
        $modulesByLayer = $modules->groupBy(fn (Module $module): string => $layerOf[$module->id]);

        // The biggest modules of each layer, then the biggest of those overall.
        $shown = $this->biggest(
            $modulesByLayer->flatMap(fn (Collection $inLayer): Collection => $this->biggest($inLayer, self::MAX_MODULES_PER_LAYER)),
            self::MAX_LAYER_MODULES,
        );
        $ids = $this->nodeIds($shown, 'm');
        $chart = new Flowchart('TB', self::LAYERED_ELK_OPTIONS);
        $drawnLayers = [];

        foreach (LayerClassifier::LAYERS as $layer => $title) {
            $inLayer = $modulesByLayer->get($layer, collect());

            if ($inLayer->isEmpty()) {
                continue;
            }

            $chart->startGroup("layer_{$layer}", MermaidText::escape($title));
            $nodeIds = [];

            foreach ($shown->filter(fn (Module $module): bool => $layerOf[$module->id] === $layer) as $module) {
                $chart->node($ids[$module->id], MermaidText::escape($module->path), "layer_{$layer}");
                $nodeIds[] = $ids[$module->id];
            }

            $hiddenCount = $inLayer->count() - count($nodeIds);

            if ($hiddenCount > 0) {
                $chart->node("more_{$layer}", MermaidText::escape('+ '.$this->countLabel($hiddenCount, 'more module', 'more modules')), 'more');
                $nodeIds[] = "more_{$layer}";
            }

            $this->gridLinks($chart, $nodeIds);

            $chart->endGroup();
            $drawnLayers[] = $layer;
        }

        // Real arrows can point upwards or be missing between two layers,
        // so links that are not drawn keep the layers in order, from the top down.
        foreach (array_slice($drawnLayers, 1) as $index => $layer) {
            $chart->invisibleLink("layer_{$drawnLayers[$index]}", "layer_{$layer}");
        }

        $layerArrows = $this->layerDependencies($dependencies, $layerOf->all());

        foreach ($layerArrows as [$fromLayer, $toLayer]) {
            $chart->edge("layer_{$fromLayer}", "layer_{$toLayer}");
        }

        foreach (self::LAYER_STYLES as $layer => $style) {
            $chart->classDef("layer_{$layer}", $style);
        }

        $chart->classDef('more', self::C4_STYLES['more']);

        // An arrow between layers stands for all the imports between their modules; imports inside a layer are not drawn.
        $drawnImports = array_sum(array_map(
            fn (array $dependency): int => $layerOf[$dependency['from']] === $layerOf[$dependency['to']] ? 0 : $dependency['weight'],
            $dependencies,
        ));

        $caption = ($shown->count() < $modules->count()
            ? "The {$shown->count()} biggest of the {$modules->count()} modules with code, grouped into layers by folder name"
            : 'The modules with code ('.$this->countLabel($modules->count(), 'module', 'modules').'), grouped into layers by folder name')
            .'; an arrow means that modules of one layer import code from the other layer.';

        return $this->diagram(DiagramType::Layers, $chart, [
            'title' => 'Layered architecture',
            'caption' => $caption,
            'excluded' => $excluded,
            'nodes' => $this->nodePaths($shown, $ids),
            'findings' => $this->facts->findings($repository, $modules, $shown, $dependencies, $drawnImports, $layerOf->all()),
            'evidence' => $this->facts->layerEvidence($repository, $dependencies, $layerArrows, $layerOf->all(), $ids),
        ]);
    }

    /**
     * "Containers" (C4 level 2): what runs separately, with the technology that proves it.
     *
     * Each project (folder with a manifest) gives: its server (a web framework, or just its language),
     * its pages in the browser (React, Vue, ...) as a separate container, a queue worker when it has
     * Laravel jobs, and the databases, caches and external services of its manifest and .env.example.
     * An arrow is drawn only when the project's own files connect the two ends.
     *
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function containerView(Repository $repository, TechnologyReport $report): ?array
    {
        $projects = $this->drawnProjects($report);

        if ($projects === []) {
            return null;
        }

        $chart = new Flowchart('LR');
        $edges = [];
        $stores = [];
        $externals = [];
        $hasPeople = false;

        $chart->startGroup('system', '<b>'.MermaidText::escape($repository->name).'</b>', 'boundary');

        foreach ($projects as $index => $project) {
            $prefix = 'p'.($index + 1);
            $server = $project->first(TechnologyRole::Fullstack) ?? $project->first(TechnologyRole::Backend);
            $pages = $project->first(TechnologyRole::Ui);
            $serverLanguages = $this->serverLanguages($project);
            $serverId = null;
            $pagesId = null;

            // A server: a web framework, or code in a server language (PHP, Python, Go, ...), or a project without pages.
            if ($server !== null || $serverLanguages !== [] || $pages === null) {
                $serverId = "{$prefix}_app";
                $title = $this->projectTitle($repository, $project, $server === null ? null : 'Web application');
                $details = $this->joinNames([$server, ...$serverLanguages]);
                $description = match (true) {
                    $server?->role === TechnologyRole::Fullstack => 'Server and pages',
                    $server !== null => 'Web server',
                    // Without a framework, the tools describe the project, unless they belong to its pages (Vite).
                    $pages === null => $this->toolsLine($project),
                    default => null,
                };
                $this->container($chart, $serverId, $server ?? $serverLanguages[0] ?? null, $title, array_filter([$details, $description]));
            }

            if ($pages !== null) {
                $pagesId = "{$prefix}_ui";
                $title = $this->projectTitle($repository, $project, 'Web interface', $serverId !== null ? 'interface' : null);
                $details = $this->joinNames([$pages, $this->pagesLanguage($project)]);
                $this->container($chart, $pagesId, $pages, $title, [$details, 'Pages in the browser']);

                if ($serverId !== null) {
                    $edges[] = [$pagesId, $serverId, $project->uses('inertia') ? 'Inertia requests · HTTP' : 'HTTP requests'];
                }
            }

            $mainId = $serverId ?? $pagesId;

            if ($server !== null || $pages !== null) {
                // Only the first arrow from the user is labeled: the others would repeat the same words.
                $edges[] = ['user', $pagesId ?? $serverId, $hasPeople ? '' : 'Uses · HTTPS'];
                $hasPeople = true;
            }

            $workerId = null;

            if ($project->hasQueueJobs && $project->uses('laravel')) {
                $workerId = "{$prefix}_worker";
                $this->container($chart, $workerId, $project->first(TechnologyRole::Backend), 'Queue worker', ['Laravel · queue:work', 'Runs the jobs in the background']);
            }

            foreach ([...$project->withRole(TechnologyRole::Database), ...$project->withRole(TechnologyRole::Cache)] as $store) {
                $stores[$store->key] ??= $store;
                $edges[] = [$mainId, "store_{$store->key}", $store->role === TechnologyRole::Database ? 'Reads and writes' : $this->cacheUsage($project, $store)];

                if ($workerId !== null && $this->queueStore($project) === $store->key) {
                    $edges[] = [$workerId, "store_{$store->key}", 'Takes the jobs'];
                }
            }

            foreach ($project->withRole(TechnologyRole::External) as $external) {
                $externals[$external->key] ??= $external;
                $edges[] = [$mainId, "ext_{$external->key}", $external->key === 'smtp' ? 'Sends email · SMTP' : 'API · HTTPS'];
            }
        }

        // Servers started by docker-compose or Kubernetes next to the code, even without an arrow.
        foreach ($report->shared as $technology) {
            if (in_array($technology->role, [TechnologyRole::Database, TechnologyRole::Cache], true)) {
                $stores[$technology->key] ??= $technology;
            }
        }

        foreach ($stores as $key => $store) {
            $title = $store->role === TechnologyRole::Database ? 'Database' : $store->name;
            $details = $store->role === TechnologyRole::Database ? [$store->name] : [];
            $this->container($chart, "store_{$key}", $store, $title, $details, cylinder: $store->role === TechnologyRole::Database);
        }

        $hiddenProjects = count($report->projects) - count($projects);

        if ($hiddenProjects > 0) {
            $chart->node('more', MermaidText::escape('+ '.$this->countLabel($hiddenProjects, 'more package', 'more packages')), 'more');
        }

        $chart->endGroup();

        if ($hasPeople) {
            $chart->node('user', MermaidText::label('User', ['Uses the application']), 'person', 'person');
        }

        foreach ($externals as $key => $external) {
            $this->external($chart, "ext_{$key}", $external);
        }

        foreach ($this->uniqueEdges($edges) as [$from, $to, $label]) {
            $chart->edge($from, $to, $label === '' ? null : MermaidText::escape($label));
        }

        $this->defineC4Styles($chart);

        return $this->diagram(DiagramType::Container, $chart, [
            'title' => 'Containers',
            'caption' => 'The parts that run separately and their technologies, found in '.$this->sourcesSentence($report).'; an arrow points from the caller to what it calls.',
            'technologies' => array_map(fn (Technology $technology): array => $technology->toArray(), $report->all()),
            'hidden_projects' => $hiddenProjects,
        ]);
    }

    /**
     * The components diagram: of modules, or, when the repository has fewer than three modules with code
     * (everything sits in one or two folders, or in the root), of files, when that shows more than the
     * modules would (more files than modules) and there are at least two files to connect.
     *
     * @param  Collection<int, Module>  $modules
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function componentsDiagram(Repository $repository, Collection $modules, array $dependencies): ?array
    {
        if ($modules->count() < self::MIN_MODULES_FOR_MODULE_COMPONENTS) {
            $diagram = $this->fileComponentView($repository, $modules->count());

            if ($diagram !== null) {
                return $diagram;
            }
        }

        return $this->componentView($repository, $modules, $dependencies);
    }

    /**
     * "Components" at file level, for a small or flat repository: the biggest files (up to 20) with their
     * main symbols, inside the system boundary, and the imports between them as arrows. The findings and
     * the evidence mean the same as for modules, over files. Null when the files are no more than the
     * modules (the module diagram already shows each of them).
     *
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function fileComponentView(Repository $repository, int $moduleCount): ?array
    {
        $files = $this->overview->applicationFiles($repository);

        if ($files->count() < 2 || $files->count() <= $moduleCount) {
            return null;
        }

        $dependencies = $this->overview->dependenciesBetweenFiles($repository, $files);
        $shown = $this->biggest($files, self::MAX_COMPONENT_FILES);
        $ids = $this->nodeIds($shown, 'f');
        $symbolNames = $this->overview->mainSymbolNames($shown, self::MAX_CLASS_NAMES);
        $omitted = $files->count() - $shown->count();

        // A file is called by its name; when two get the same name, by their whole path.
        $names = $shown->mapWithKeys(fn (Module $file): array => [$file->id => basename($file->path)]);
        $repeatedNames = $names->duplicates()->all();

        $chart = new Flowchart('TB');
        $chart->startGroup('system', '<b>'.MermaidText::escape($repository->name).'</b> · code files', 'boundary');

        foreach ($shown as $file) {
            $shortName = in_array($names[$file->id], $repeatedNames, true) ? $file->path : $names[$file->id];
            $folder = dirname($file->path);
            $details = array_values(array_filter([
                $shortName === $file->path || $folder === '.' ? null : $folder,
                ...($symbolNames[$file->id] ?? []),
            ]));

            $chart->node($ids[$file->id], MermaidText::label($shortName, $details), 'component');
        }

        if ($omitted > 0) {
            $chart->node('more', MermaidText::escape('+ '.$this->countLabel($omitted, 'more file', 'more files')), 'more');
        }

        $drawnEdges = $this->drawDependencies($chart, $dependencies, $ids, self::MAX_COMPONENT_EDGES);

        $connected = array_unique(array_merge([], ...$drawnEdges));
        $unconnected = array_values(array_diff([...array_values($ids), ...($omitted > 0 ? ['more'] : [])], $connected));
        $this->gridLinks($chart, $unconnected);

        if ($unconnected !== [] && $drawnEdges !== []) {
            $chart->invisibleLink($unconnected[array_key_last($unconnected)], $drawnEdges[0][0]);
        }

        $chart->endGroup();
        $this->defineC4Styles($chart);

        $nodeToFile = array_flip($ids);
        $drawnFiles = array_map(fn (array $edge): array => [$nodeToFile[$edge[0]], $nodeToFile[$edge[1]]], $drawnEdges);
        $weights = [];

        foreach ($dependencies as $dependency) {
            $weights[$dependency['from'].':'.$dependency['to']] = $dependency['weight'];
        }

        $drawnImports = array_sum(array_map(fn (array $edge): int => $weights[$edge[0].':'.$edge[1]], $drawnFiles));
        $possibleEdges = count(array_filter($dependencies, fn (array $dependency): bool => isset($ids[$dependency['from']], $ids[$dependency['to']])));

        $caption = ($omitted > 0
            ? "The {$shown->count()} biggest of the {$files->count()} files with code, with their main symbols"
            : 'The files with code, with their main symbols')
            .match (true) {
                $drawnEdges === [] => '; they do not import each other.',
                count($drawnEdges) < $possibleEdges => ', and the '.(count($drawnEdges) === 1 ? 'strongest dependency' : count($drawnEdges).' strongest dependencies').'; an arrow points from the importing file to the imported one.',
                default => '; an arrow means that a file imports code from another.',
            };

        return $this->diagram(DiagramType::Component, $chart, [
            'title' => 'Components',
            'caption' => $caption,
            'level' => 'files',
            'omitted_modules' => $omitted,
            'nodes' => $this->nodePaths($shown, $ids),
            'classes' => $this->classesByNode($symbolNames, $ids),
            'findings' => $this->facts->findings($repository, $files, $shown, $dependencies, $drawnImports, null, true),
            'evidence' => $this->facts->edgeEvidence($repository, $files, $dependencies, $drawnFiles, $ids, true),
        ]);
    }

    /**
     * "Components" (C4 level 3): the biggest modules with their main classes, inside the system boundary.
     *
     * @param  Collection<int, Module>  $modules
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function componentView(Repository $repository, Collection $modules, array $dependencies): ?array
    {
        if ($modules->count() < 2) {
            return null;
        }

        $shown = $this->biggest($modules, self::MAX_COMPONENTS);
        $ids = $this->nodeIds($shown, 'c');
        $classNames = $this->overview->mainClassNames($shown, self::MAX_CLASS_NAMES);
        $omitted = $modules->count() - $shown->count();

        $chart = new Flowchart('TB');
        $chart->startGroup('system', '<b>'.MermaidText::escape($repository->name).'</b> · code components', 'boundary');

        // A component is called by its folder name; when two get the same name, by their whole path.
        $names = $shown->mapWithKeys(fn (Module $module): array => [$module->id => $this->componentName($module->path)]);
        $repeatedNames = $names->duplicates()->all();

        foreach ($shown as $module) {
            $shortName = in_array($names[$module->id], $repeatedNames, true) ? $module->path : $names[$module->id];
            $details = array_values(array_filter([
                $shortName === $module->path ? null : $module->path,
                ...($classNames[$module->id] ?? []),
            ]));

            $chart->node($ids[$module->id], MermaidText::label($shortName, $details), 'component');
        }

        if ($omitted > 0) {
            $chart->node('more', MermaidText::escape('+ '.$this->countLabel($omitted, 'more module', 'more modules')), 'more');
        }

        $drawnEdges = $this->drawDependencies($chart, $dependencies, $ids, self::MAX_COMPONENT_EDGES);

        // The modules without arrows go in a grid above the others, instead of one very wide row.
        $connected = array_unique(array_merge([], ...$drawnEdges));
        $unconnected = array_values(array_diff([...array_values($ids), ...($omitted > 0 ? ['more'] : [])], $connected));
        $this->gridLinks($chart, $unconnected);

        if ($unconnected !== [] && $drawnEdges !== []) {
            $chart->invisibleLink($unconnected[array_key_last($unconnected)], $drawnEdges[0][0]);
        }

        $chart->endGroup();
        $this->defineC4Styles($chart);

        $nodeToModule = array_flip($ids);
        $drawnModules = array_map(fn (array $edge): array => [$nodeToModule[$edge[0]], $nodeToModule[$edge[1]]], $drawnEdges);
        $moduleWeights = [];

        foreach ($dependencies as $dependency) {
            $moduleWeights[$dependency['from'].':'.$dependency['to']] = $dependency['weight'];
        }

        $drawnImports = array_sum(array_map(fn (array $edge): int => $moduleWeights[$edge[0].':'.$edge[1]], $drawnModules));
        $possibleEdges = count(array_filter($dependencies, fn (array $dependency): bool => isset($ids[$dependency['from']], $ids[$dependency['to']])));

        $caption = ($omitted > 0
            ? "The {$shown->count()} biggest of the {$modules->count()} modules with code, with their main classes"
            : 'The modules with code, with their main classes')
            .match (true) {
                $drawnEdges === [] => '; they do not import each other.',
                count($drawnEdges) < $possibleEdges => ', and the '.(count($drawnEdges) === 1 ? 'strongest dependency' : count($drawnEdges).' strongest dependencies').'; an arrow points from the importing module to the imported one.',
                default => '; an arrow means that a module imports code from another.',
            };

        return $this->diagram(DiagramType::Component, $chart, [
            'title' => 'Components',
            'caption' => $caption,
            'omitted_modules' => $omitted,
            'nodes' => $this->nodePaths($shown, $ids),
            'classes' => $this->classesByNode($classNames, $ids),
            'findings' => $this->facts->findings($repository, $modules, $shown, $dependencies, $drawnImports, null),
            'evidence' => $this->facts->edgeEvidence($repository, $modules, $dependencies, $drawnModules, $ids),
        ]);
    }

    /**
     * "Context" (C4 level 1), small: the user, the system and the external systems it calls.
     *
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function contextView(Repository $repository, TechnologyReport $report): ?array
    {
        $projects = $this->drawnProjects($report);
        $hasPeople = collect($projects)->contains(fn (DetectedProject $project): bool => $project->first(TechnologyRole::Backend) !== null
            || $project->first(TechnologyRole::Fullstack) !== null
            || $project->first(TechnologyRole::Ui) !== null);
        $externals = array_values(array_filter($report->all(), fn (Technology $technology): bool => $technology->role === TechnologyRole::External));

        if (! $hasPeople && $externals === []) {
            return null;
        }

        $chart = new Flowchart('LR');
        $chart->node('system', MermaidText::label($repository->name, array_values(array_filter([$repository->description])), detailLength: 220), 'system');

        if ($hasPeople) {
            $chart->node('user', MermaidText::label('User', ['Uses the application']), 'person', 'person');
            $chart->edge('user', 'system', MermaidText::escape('Uses'));
        }

        foreach ($externals as $external) {
            $this->external($chart, "ext_{$external->key}", $external);
            $chart->edge('system', "ext_{$external->key}", MermaidText::escape($external->key === 'smtp' ? 'Sends email' : 'Sends requests'));
        }

        $this->defineC4Styles($chart);

        return $this->diagram(DiagramType::Context, $chart, [
            'title' => 'Context',
            // The external systems drawn, so the reading of the diagram may name them (the page does not list them).
            'externals' => array_map(fn (Technology $technology): array => $technology->toArray(), $externals),
            'caption' => $externals === []
                ? 'Who uses the system; the project files show no external systems called by the code.'
                : 'Who uses the system and which external systems the code calls (from the libraries and settings found).',
        ]);
    }

    /**
     * The projects drawn as containers: those with at least one recognized technology; all of them
     * when there are only a few, otherwise only those that run a server or pages (a big monorepo
     * has many library packages).
     *
     * @return list<DetectedProject>
     */
    private function drawnProjects(TechnologyReport $report): array
    {
        $projects = array_values(array_filter($report->projects, fn (DetectedProject $project): bool => $project->technologies !== []));

        if (count($projects) > self::MAX_PROJECTS) {
            $projects = array_values(array_filter($projects, fn (DetectedProject $project): bool => $project->first(TechnologyRole::Backend) !== null
                || $project->first(TechnologyRole::Fullstack) !== null
                || $project->first(TechnologyRole::Ui) !== null));
        }

        return array_slice($projects, 0, self::MAX_PROJECTS);
    }

    /**
     * Draw a container: its logo when the technology has one, a blue box (or cylinder) otherwise.
     *
     * @param  array<int, string>  $details
     */
    private function container(Flowchart $chart, string $id, ?Technology $technology, string $title, array $details, bool $cylinder = false): void
    {
        $label = MermaidText::label($title, array_values($details));

        if ($technology?->icon !== null) {
            $chart->iconNode($id, $technology->icon, $label, 'container');
        } else {
            $chart->node($id, $label, 'containerBox', $cylinder ? 'cylinder' : 'rect');
        }
    }

    /**
     * Draw an external system: its logo when it has one, a grey box otherwise.
     */
    private function external(Flowchart $chart, string $id, Technology $technology): void
    {
        $label = MermaidText::label($technology->name, ['External system']);

        if ($technology->icon !== null) {
            $chart->iconNode($id, $technology->icon, $label, 'external');
        } else {
            $chart->node($id, $label, 'externalBox');
        }
    }

    /**
     * The name of a component: its folder name, or the folder above it when that name says nothing
     * ("packages/core/src" → "core").
     */
    private function componentName(string $modulePath): string
    {
        $folders = explode('/', $modulePath);
        $name = array_pop($folders);

        if ($folders !== [] && in_array(mb_strtolower($name), self::GENERIC_FOLDERS, true)) {
            return array_pop($folders);
        }

        return $name;
    }

    /**
     * The title of a project's container: the generic name for the root project ("Web application"),
     * the folder name for a project in a subfolder ("analyzer"), the repository name otherwise.
     */
    private function projectTitle(Repository $repository, DetectedProject $project, ?string $generic, ?string $suffix = null): string
    {
        if ($project->folder !== '') {
            return basename($project->folder).($suffix === null ? '' : " · {$suffix}");
        }

        return $generic ?? $repository->name;
    }

    /**
     * The languages of a project's server: PHP, Node.js, Python, Go, ... (TypeScript and JavaScript
     * belong to the pages when the project has pages).
     *
     * @return list<Technology>
     */
    private function serverLanguages(DetectedProject $project): array
    {
        return array_values(array_filter(
            $project->withRole(TechnologyRole::Language),
            fn (Technology $language): bool => $project->first(TechnologyRole::Ui) === null || $language !== $this->pagesLanguage($project),
        ));
    }

    /**
     * The language of a project's pages: TypeScript or JavaScript, from its package.json.
     */
    private function pagesLanguage(DetectedProject $project): ?Technology
    {
        foreach ($project->withRole(TechnologyRole::Language) as $language) {
            if (in_array($language->key, ['typescript', 'javascript'], true)) {
                return $language;
            }
        }

        return null;
    }

    /**
     * The tools of a project without a framework ("tree-sitter · Vite"), or null.
     */
    private function toolsLine(DetectedProject $project): ?string
    {
        return $this->joinNames($project->withRole(TechnologyRole::Tool)) ?: null;
    }

    /**
     * What a project uses a cache server for, from its .env.example.
     */
    private function cacheUsage(DetectedProject $project, Technology $store): string
    {
        $driverKeys = [
            'Cache' => [$project->environment['CACHE_STORE'] ?? $project->environment['CACHE_DRIVER'] ?? null],
            'Sessions' => [$project->environment['SESSION_DRIVER'] ?? null],
            'Job queue' => [$project->environment['QUEUE_CONNECTION'] ?? null],
        ];

        $uses = [];

        foreach ($driverKeys as $use => [$driver]) {
            if ($driver !== null && (TechnologyCatalog::CACHE_STORES[$driver] ?? TechnologyCatalog::QUEUE_CONNECTIONS[$driver] ?? null) === $store->key) {
                $uses[] = $use;
            }
        }

        return $uses === [] ? 'Uses' : implode(' · ', $uses);
    }

    /**
     * The technology key of the server a Laravel queue worker takes its jobs from:
     * the database by default, or the server named by QUEUE_CONNECTION.
     */
    private function queueStore(DetectedProject $project): ?string
    {
        $connection = $project->environment['QUEUE_CONNECTION'] ?? 'database';

        if ($connection === 'database') {
            return $project->first(TechnologyRole::Database)?->key;
        }

        return TechnologyCatalog::QUEUE_CONNECTIONS[$connection] ?? null;
    }

    /**
     * The names of the technologies, joined with " · " (null entries are skipped).
     *
     * @param  array<int, Technology|null>  $technologies
     */
    private function joinNames(array $technologies): string
    {
        $names = array_map(fn (Technology $technology): string => $technology->name, array_filter($technologies));

        return implode(' · ', array_unique($names));
    }

    /**
     * Where the technologies were found, as a list: "composer.json, package.json, and .env.example".
     */
    private function sourcesSentence(TechnologyReport $report): string
    {
        $sources = array_values(array_unique(array_map(fn (Technology $technology): string => basename($technology->source), $report->all())));

        if (count($sources) <= 1) {
            return $sources[0] ?? 'the project files';
        }

        if (count($sources) === 2) {
            return implode(' and ', $sources);
        }

        return implode(', ', array_slice($sources, 0, -1)).', and '.end($sources);
    }

    /**
     * The pairs of different layers whose modules import each other, in the order of LAYERS:
     * [from layer, to layer]. Every dependency counts, also those of modules that are not drawn.
     *
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies
     * @param  array<int, string>  $layerOf  module id => layer key
     * @return list<array{string, string}>
     */
    private function layerDependencies(array $dependencies, array $layerOf): array
    {
        $order = array_flip(array_keys(LayerClassifier::LAYERS));
        $pairs = [];

        foreach ($dependencies as $dependency) {
            $from = $layerOf[$dependency['from']];
            $to = $layerOf[$dependency['to']];

            if ($from !== $to) {
                $pairs["{$from}→{$to}"] = [$from, $to];
            }
        }

        usort($pairs, fn (array $a, array $b): int => [$order[$a[0]], $order[$a[1]]] <=> [$order[$b[0]], $order[$b[1]]]);

        return $pairs;
    }

    /**
     * Draw the strongest dependencies whose both ends are on the diagram, and return them as
     * [from node id, to node id], strongest first.
     *
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies
     * @param  array<int, string>  $ids  module id => node id
     * @return list<array{string, string}>
     */
    private function drawDependencies(Flowchart $chart, array $dependencies, array $ids, int $limit): array
    {
        $drawn = [];

        foreach ($dependencies as $dependency) {
            if (count($drawn) === $limit) {
                break;
            }

            if (isset($ids[$dependency['from']], $ids[$dependency['to']])) {
                $chart->edge($ids[$dependency['from']], $ids[$dependency['to']]);
                $drawn[] = [$ids[$dependency['from']], $ids[$dependency['to']]];
            }
        }

        return $drawn;
    }

    /**
     * Put boxes that have no arrows between them in rows of GRID_COLUMNS: a link that is not drawn
     * from each box to the one GRID_COLUMNS places later moves that one a row down.
     *
     * @param  list<string>  $nodeIds
     */
    private function gridLinks(Flowchart $chart, array $nodeIds): void
    {
        foreach (array_slice($nodeIds, self::GRID_COLUMNS) as $index => $nodeId) {
            $chart->invisibleLink($nodeIds[$index], $nodeId);
        }
    }

    /**
     * The biggest modules (by lines of code, then by path), in path order.
     *
     * @param  Collection<int, Module>  $modules
     * @return Collection<int, Module>
     */
    private function biggest(Collection $modules, int $limit): Collection
    {
        return $modules
            ->sortBy([['line_count', 'desc'], ['path', 'asc']])
            ->take($limit)
            ->sortBy('path')
            ->values();
    }

    /**
     * Which module each drawn node stands for (node id => module path), so the page can link a box to its docs.
     *
     * @param  Collection<int, Module>  $modules
     * @param  array<int, string>  $ids  module id => node id
     * @return array<string, string>
     */
    private function nodePaths(Collection $modules, array $ids): array
    {
        return $modules->mapWithKeys(fn (Module $module): array => [$ids[$module->id] => $module->path])->all();
    }

    /**
     * The class names each node shows (node id => names), so the reading of the diagram may name them.
     *
     * @param  array<int, list<string>>  $classNames  module id => class names
     * @param  array<int, string>  $ids  module id => node id
     * @return array<string, list<string>>
     */
    private function classesByNode(array $classNames, array $ids): array
    {
        $byNode = [];

        foreach ($ids as $moduleId => $nodeId) {
            if (($classNames[$moduleId] ?? []) !== []) {
                $byNode[$nodeId] = $classNames[$moduleId];
            }
        }

        return $byNode;
    }

    /**
     * A short node id for each module, numbered in path order: m1, m2, ...
     *
     * @param  Collection<int, Module>  $modules
     * @return array<int, string> module id => node id
     */
    private function nodeIds(Collection $modules, string $prefix): array
    {
        return $modules->values()->mapWithKeys(fn (Module $module, int $index): array => [$module->id => $prefix.($index + 1)])->all();
    }

    /**
     * Remove repeated arrows (two projects can point to the same database with the same words).
     *
     * @param  list<array{string, string, string}>  $edges
     * @return list<array{string, string, string}>
     */
    private function uniqueEdges(array $edges): array
    {
        $unique = [];

        foreach ($edges as $edge) {
            $unique[implode('→', $edge)] = $edge;
        }

        return array_values($unique);
    }

    /**
     * Add the C4 style classes to a diagram.
     */
    private function defineC4Styles(Flowchart $chart): void
    {
        foreach (self::C4_STYLES as $name => $style) {
            $chart->classDef($name, $style);
        }
    }

    /**
     * A number with its noun: "1 module", "16 modules".
     */
    private function countLabel(int $number, string $one, string $many): string
    {
        return $number === 1 ? "1 {$one}" : "{$number} {$many}";
    }

    /**
     * The saved shape of one diagram.
     *
     * @param  array<string, mixed>  $meta
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}
     */
    private function diagram(DiagramType $type, Flowchart $chart, array $meta): array
    {
        return ['type' => $type, 'key' => '', 'mermaid' => $chart->toMermaid(), 'meta' => $meta];
    }
}
