<?php

namespace App\Diagrams;

use App\Analysis\LinkCatalog;
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
    public const MAX_COMPONENTS = 12;

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
        'tool' => 'fill:none,stroke:#59636e,stroke-dasharray:5 4,color:#1f2328',
    ];

    /**
     * At most this many services and programs found in the code are drawn in the context and containers
     * (the most used; a big repository names hundreds of hosts in its code).
     */
    public const MAX_SERVICES = 8;

    public const MAX_TOOLS = 5;

    /**
     * The role of each module in plain words (module path => role), for the dependency maps: the part of
     * the big picture it belongs to, or the first clause of its documentation page, or its folder name.
     *
     * @var array<string, string>
     */
    private array $roles = [];

    /**
     * Create a new builder.
     */
    public function __construct(
        private TechnologyDetector $detector,
        private CodeOverview $overview,
        private ArchitectureFacts $facts,
        private SystemFacts $systemFacts,
    ) {}

    /**
     * Every architecture diagram that the repository's data supports.
     *
     * @return list<array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}>
     */
    public function build(Repository $repository): array
    {
        [$modules, $excluded] = $this->overview->applicationModules($repository);
        // Every kind of link, not only the imports: routes, dispatched jobs, rendered pages, scripts started, ...
        $dependencies = $this->overview->linksBetween($repository, $modules);
        $report = $this->detector->detect($repository);
        $this->roles = $this->moduleRoles($repository, $modules);

        $facts = $this->systemFacts->collect($repository);

        return array_values(array_filter([
            $this->layersView($repository, $modules, $dependencies, $excluded),
            $this->containerView($repository, $report, $facts),
            $this->componentsDiagram($repository, $modules, $dependencies),
            $this->contextView($repository, $report, $facts),
        ]));
    }

    /**
     * "Layers": one box per layer that has code (entry points, interface, web, logic, data), with the number
     * of its modules, and one arrow from a layer to another when its modules link to the other's, labelled
     * with what the links do and how many there are. A layer without any arrow is not drawn but listed.
     *
     * @param  Collection<int, Module>  $modules
     * @param  list<array{from: int, to: int, weight: int, kinds?: array<string, int>}>  $dependencies
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
        $layerArrows = $this->layerDependencies($dependencies, $layerOf->all());
        $connected = array_unique(array_merge([], ...array_map(fn (array $arrow): array => [$arrow[0], $arrow[1]], $layerArrows)));
        $chart = new Flowchart('TB', self::LAYERED_ELK_OPTIONS);
        $unconnected = [];

        foreach (LayerClassifier::LAYERS as $layer => $title) {
            $inLayer = $modulesByLayer->get($layer, collect());

            if ($inLayer->isEmpty()) {
                continue;
            }

            if (! in_array($layer, $connected, true)) {
                array_push($unconnected, ...$inLayer->pluck('path')->all());

                continue;
            }

            $chart->node("layer_{$layer}", MermaidText::label($title, [$this->countLabel($inLayer->count(), 'module', 'modules')]), "layer_{$layer}");
        }

        foreach ($layerArrows as [$fromLayer, $toLayer, $kinds]) {
            $chart->edge("layer_{$fromLayer}", "layer_{$toLayer}", MermaidText::escape($this->arrowWords($kinds)));
        }

        foreach (self::LAYER_STYLES as $layer => $style) {
            $chart->classDef("layer_{$layer}", $style);
        }

        $drawnImports = array_sum(array_map(
            fn (array $dependency): int => $layerOf[$dependency['from']] === $layerOf[$dependency['to']] ? 0 : $dependency['weight'],
            $dependencies,
        ));

        if ($layerArrows === []) {
            return null;
        }

        return $this->diagram(DiagramType::Layers, $chart, [
            'title' => 'Layers',
            'caption' => 'The code grouped into layers by folder name; an arrow means that modules of one layer use the other, with what the links do and how many there are.',
            'excluded' => $excluded,
            'unconnected' => $unconnected,
            'layers' => $modulesByLayer->map(fn (Collection $inLayer): array => $inLayer->pluck('path')->all())->all(),
            'nodes' => [],
            'findings' => $this->facts->findings($repository, $modules, $modules, $dependencies, $drawnImports, $layerOf->all()),
            'evidence' => $this->facts->layerEvidence($repository, $dependencies, array_map(fn (array $arrow): array => [$arrow[0], $arrow[1]], $layerArrows), $layerOf->all(), []),
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
     * @param  array<string, mixed>  $facts  SystemFacts
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function containerView(Repository $repository, TechnologyReport $report, array $facts): ?array
    {
        $projects = $this->drawnProjects($report);

        if ($projects === []) {
            return null;
        }

        $chart = new Flowchart('LR');
        $edges = [];
        $stores = [];
        $externals = [];
        $pending = [];
        $mainOf = [];
        $workerOf = [];
        $hasPeople = false;

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
                $pending[$serverId] = [$server ?? $serverLanguages[0] ?? null, $title, array_filter([$details, $description]), false];
            }

            if ($pages !== null) {
                $pagesId = "{$prefix}_ui";
                $title = $this->projectTitle($repository, $project, 'Web interface', $serverId !== null ? 'interface' : null);
                $details = $this->joinNames([$pages, $this->pagesLanguage($project)]);
                $pending[$pagesId] = [$pages, $title, [$details, 'Pages in the browser'], false];

                if ($serverId !== null) {
                    $edges[] = [$pagesId, $serverId, $project->uses('inertia') ? 'Inertia requests · HTTP' : 'HTTP requests'];
                }
            }

            $mainId = $serverId ?? $pagesId;
            $mainOf[$project->folder] = $mainId;

            if ($server !== null || $pages !== null) {
                // Only the first arrow from the user is labeled: the others would repeat the same words.
                $edges[] = ['user', $pagesId ?? $serverId, $hasPeople ? '' : 'Uses · HTTPS'];
                $hasPeople = true;
            }

            if ($project->hasQueueJobs && $project->uses('laravel')) {
                $workerOf[$project->folder] = "{$prefix}_worker";
                $pending["{$prefix}_worker"] = [$project->first(TechnologyRole::Backend), 'Queue worker', ['Laravel · queue:work', 'Runs the jobs in the background'], false];
            }

            foreach ([...$project->withRole(TechnologyRole::Database), ...$project->withRole(TechnologyRole::Cache)] as $store) {
                $stores[$store->key] ??= $store;
                $edges[] = [$mainId, "store_{$store->key}", $store->role === TechnologyRole::Database ? 'Reads and writes' : $this->cacheUsage($project, $store)];

                if (isset($workerOf[$project->folder]) && $this->queueStore($project) === $store->key) {
                    $edges[] = [$mainId, "store_{$store->key}", 'Queues jobs'];
                    $edges[] = ["store_{$store->key}", $workerOf[$project->folder], 'Hands out the jobs'];
                    $edges[] = [$workerOf[$project->folder], "store_{$store->key}", 'Reads and writes'];
                }
            }

            foreach ($project->withRole(TechnologyRole::External) as $external) {
                $externals[$this->externalKey($external->key)] ??= ['name' => $external->name, 'icon' => $external->icon, 'tool' => false];
                $edges[] = [$mainId, 'ext_'.$this->externalKey($external->key), $external->key === 'smtp' ? 'Sends email · SMTP' : 'API · HTTPS'];
            }
        }

        array_push($edges, ...$this->evidenceEdges($projects, $facts, $mainOf, $workerOf, $externals));

        // Servers started by docker-compose or Kubernetes next to the code, only when something uses them.
        foreach ($report->shared as $technology) {
            if (in_array($technology->role, [TechnologyRole::Database, TechnologyRole::Cache], true)) {
                $stores[$technology->key] ??= $technology;
            }
        }

        foreach ($stores as $key => $store) {
            $title = $store->role === TechnologyRole::Database ? 'Database' : $store->name;
            $pending["store_{$key}"] = [$store, $title, $store->role === TechnologyRole::Database ? [$store->name] : [], $store->role === TechnologyRole::Database];
        }

        $edges = $this->uniqueEdges(array_map(fn (array $edge): array => [$edge[0], $edge[1], $edge[2]], $edges));
        $connected = array_flip(array_merge(array_column($edges, 0), array_column($edges, 1)));
        $unconnected = [];
        $chart->startGroup('system', '<b>'.MermaidText::escape($repository->name).'</b>', 'boundary');

        foreach ($pending as $id => [$technology, $title, $details, $cylinder]) {
            if (isset($connected[$id])) {
                $this->container($chart, $id, $technology, $title, array_values($details), $cylinder);
            } else {
                $unconnected[] = $title;
            }
        }

        $chart->endGroup();

        if ($hasPeople) {
            $chart->node('user', MermaidText::label('User', ['Uses the application']), 'person', 'person');
        }

        foreach ($externals as $key => $external) {
            $this->outsideNode($chart, ($external['tool'] ? 'tool_' : 'ext_').$key, $external);
        }

        foreach ($edges as [$from, $to, $label]) {
            $chart->edge($from, $to, $label === '' ? null : MermaidText::escape($label));
        }

        $this->defineC4Styles($chart);

        return $this->diagram(DiagramType::Container, $chart, [
            'title' => 'Containers',
            'caption' => 'The parts that run separately, connected by what the code shows: the technologies found in '.$this->sourcesSentence($report).', the programs the code starts and the services it calls; an arrow points from the caller to what it calls.',
            'technologies' => array_map(fn (Technology $technology): array => $technology->toArray(), $report->all()),
            'hidden_projects' => count($report->projects) - count($projects),
            'unconnected' => $unconnected,
        ]);
    }

    /**
     * The arrows the evidence of the code adds to the containers: a project that starts a script of another
     * project ("Runs"), and the programs and services the code uses — drawn from the queue worker when the
     * code that uses them runs in the background jobs (the jobs and the modules they call), else from the
     * project's main container.
     *
     * @param  list<DetectedProject>  $projects
     * @param  array<string, mixed>  $facts
     * @param  array<string, string>  $mainOf  project folder => container id
     * @param  array<string, string>  $workerOf  project folder => worker container id
     * @param  array<string, array{name: string, icon: string|null, tool: bool}>  $externals
     * @return list<array{string, string, string}>
     */
    private function evidenceEdges(array $projects, array $facts, array $mainOf, array $workerOf, array &$externals): array
    {
        $projectOf = function (string $modulePath) use ($projects): ?string {
            $best = null;

            foreach ($projects as $project) {
                if (($project->folder === '' || $modulePath === $project->folder || str_starts_with($modulePath, $project->folder.'/')) && strlen($project->folder) >= strlen($best ?? '')) {
                    $best = $project->folder;
                }
            }

            return $best;
        };

        // The code that runs in the background: the dispatched jobs and what they call, two steps deep.
        $background = array_flip(array_column(array_filter($facts['links'], fn (array $link): bool => isset($link['kinds']['dispatch'])), 'to'));

        for ($step = 0; $step < 2; $step++) {
            foreach ($facts['links'] as $link) {
                if (isset($background[$link['from']])) {
                    $background[$link['to']] = true;
                }
            }
        }

        $caller = function (string $module) use ($projectOf, $mainOf, $workerOf, $background): ?string {
            $project = $projectOf($module);

            return $project === null ? null : (isset($background[$module]) && isset($workerOf[$project]) ? $workerOf[$project] : ($mainOf[$project] ?? null));
        };

        $edges = [];

        foreach ($facts['links'] as $link) {
            $from = $projectOf($link['from']);
            $to = $projectOf($link['to']);

            if (isset($link['kinds']['runs']) && $from !== null && $to !== null && $from !== $to && isset($mainOf[$to]) && ($start = $caller($link['from'])) !== null) {
                $edges[] = [$start, $mainOf[$to], 'Runs'];
            }
        }

        $drawn = $this->drawnTargets($facts);

        foreach ($facts['externals'] as $row) {
            [$type, $key] = explode(':', $row['target'], 2);
            $start = $caller($row['module']);

            if ($start === null || ! isset($drawn[$row['target']]) || $key === 'mail') {
                continue;
            }

            $key = $this->externalKey($key);
            $externals[$key] ??= [
                'name' => LinkCatalog::name($row['target']),
                'icon' => $key === 'github' ? 'logos:github-icon' : (TechnologyCatalog::TECHNOLOGIES[$key][2] ?? null),
                'tool' => $type === 'tool',
            ];
            $edges[] = [$start, ($externals[$key]['tool'] ? 'tool_' : 'ext_').$key, $type === 'tool' ? 'Runs' : 'Calls · HTTPS'];
        }

        return $edges;
    }

    /**
     * The services and programs found in the code that the diagrams draw: the most used of each type.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, true>
     */
    private function drawnTargets(array $facts): array
    {
        $counts = ['service' => 0, 'tool' => 0];
        $drawn = [];

        foreach ($facts['targets'] as $target => $info) {
            $type = explode(':', $target, 2)[0];

            if (isset($counts[$type]) && $counts[$type] < ($type === 'tool' ? self::MAX_TOOLS : self::MAX_SERVICES)) {
                $counts[$type]++;
                $drawn[$target] = true;
            }
        }

        return $drawn;
    }

    /**
     * One key for the same external system, whether it was found in a manifest or in the code.
     */
    private function externalKey(string $key): string
    {
        return $key === 'github-api' ? 'github' : (string) preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    }

    /**
     * Draw something outside the system: an external system (its logo when it has one, an outlined box
     * otherwise) or a program the code starts (a dashed box).
     *
     * @param  array{name: string, icon: string|null, tool: bool}  $outside
     */
    private function outsideNode(Flowchart $chart, string $id, array $outside): void
    {
        $label = MermaidText::label($outside['name'], [$outside['tool'] ? 'Program the code starts' : 'External system']);

        if ($outside['icon'] !== null && ! $outside['tool']) {
            $chart->iconNode($id, $outside['icon'], $label, 'external');
        } else {
            $chart->node($id, $label, $outside['tool'] ? 'tool' : 'externalBox');
        }
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
        $importance = [];

        foreach ($dependencies as $dependency) {
            $importance[$dependency['from']] = ($importance[$dependency['from']] ?? 0) + $dependency['weight'];
            $importance[$dependency['to']] = ($importance[$dependency['to']] ?? 0) + $dependency['weight'];
        }

        $shown = $files
            ->filter(fn (Module $file): bool => isset($importance[$file->id]))
            ->sortBy([fn (Module $a, Module $b): int => $importance[$b->id] <=> $importance[$a->id], ['path', 'asc']])
            ->take(self::MAX_COMPONENT_FILES)
            ->sortBy('path')
            ->values();
        $ids = $this->nodeIds($shown, 'f');
        $drawn = array_slice(array_values(array_filter($dependencies, fn (array $dependency): bool => isset($ids[$dependency['from']], $ids[$dependency['to']]))), 0, self::MAX_COMPONENT_EDGES);
        $connected = array_flip(array_merge(array_column($drawn, 'from'), array_column($drawn, 'to')));
        $shown = $shown->filter(fn (Module $file): bool => isset($connected[$file->id]))->values();

        if ($shown->count() < 2) {
            return null;
        }

        $chart = new Flowchart('TB');

        foreach ($shown as $file) {
            $folder = dirname($file->path);
            $chart->node($ids[$file->id], MermaidText::label(basename($file->path), $folder === '.' ? [] : [$folder]), 'component');
        }

        foreach ($drawn as $dependency) {
            $chart->edge($ids[$dependency['from']], $ids[$dependency['to']], MermaidText::escape("uses · {$dependency['weight']}"));
        }

        $this->defineC4Styles($chart);

        $drawnFiles = array_map(fn (array $dependency): array => [$dependency['from'], $dependency['to']], $drawn);
        $unconnected = $files->filter(fn (Module $file): bool => ! isset($importance[$file->id]))->pluck('path')->values()->all();
        $leftOut = $files->count() - $shown->count() - count($unconnected);

        return $this->diagram(DiagramType::Component, $chart, [
            'title' => 'Dependency map',
            'caption' => 'The '.$this->countLabel($shown->count(), 'file', 'files').' with the most imports'
                .($leftOut > 0 ? ' ('.$this->countLabel($leftOut, 'other linked file is', 'other linked files are').' left out)' : '')
                .'; an arrow points from the importing file to the imported one, with the number of imports.',
            'level' => 'files',
            'omitted_modules' => $leftOut,
            'unconnected' => $unconnected,
            'nodes' => $this->nodePaths($shown, $ids),
            'findings' => $this->facts->findings($repository, $files, $shown, $dependencies, array_sum(array_column($drawn, 'weight')), null, true),
            'evidence' => $this->facts->edgeEvidence($repository, $files, $dependencies, $drawnFiles, $ids, true),
        ]);
    }

    /**
     * The dependency map of modules: the most important modules (the most links in and out, not the
     * biggest), each named by its role with its path underneath, and the links between them, each arrow
     * labelled with what the links do and how many there are. A module without an arrow is not drawn: it is
     * listed under the map instead.
     *
     * @param  Collection<int, Module>  $modules
     * @param  list<array{from: int, to: int, weight: int, kinds?: array<string, int>}>  $dependencies
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function componentView(Repository $repository, Collection $modules, array $dependencies): ?array
    {
        if ($modules->count() < 2) {
            return null;
        }

        $importance = [];

        foreach ($dependencies as $dependency) {
            $importance[$dependency['from']] = ($importance[$dependency['from']] ?? 0) + $dependency['weight'];
            $importance[$dependency['to']] = ($importance[$dependency['to']] ?? 0) + $dependency['weight'];
        }

        $shown = $modules
            ->filter(fn (Module $module): bool => isset($importance[$module->id]))
            ->sortBy([fn (Module $a, Module $b): int => $importance[$b->id] <=> $importance[$a->id], ['path', 'asc']])
            ->take(self::MAX_COMPONENTS)
            ->sortBy('path')
            ->values();
        $ids = $this->nodeIds($shown, 'c');
        $drawn = array_slice(array_values(array_filter($dependencies, fn (array $dependency): bool => isset($ids[$dependency['from']], $ids[$dependency['to']]))), 0, self::MAX_COMPONENT_EDGES);
        $connected = array_flip(array_merge(array_column($drawn, 'from'), array_column($drawn, 'to')));
        $shown = $shown->filter(fn (Module $module): bool => isset($connected[$module->id]))->values();

        if ($shown->count() < 2) {
            return null;
        }

        $chart = new Flowchart('TB');

        foreach ($shown as $module) {
            $chart->node($ids[$module->id], MermaidText::label($this->roleOf($module->path), [$module->path]), 'component');
        }

        foreach ($drawn as $dependency) {
            $chart->edge($ids[$dependency['from']], $ids[$dependency['to']], MermaidText::escape($this->arrowWords($dependency['kinds'] ?? ['import' => $dependency['weight']])));
        }

        $this->defineC4Styles($chart);

        $drawnModules = array_map(fn (array $dependency): array => [$dependency['from'], $dependency['to']], $drawn);
        $drawnImports = array_sum(array_column($drawn, 'weight'));
        $unconnected = $modules->filter(fn (Module $module): bool => ! isset($importance[$module->id]))->pluck('path')->values()->all();
        $leftOut = $modules->count() - $shown->count() - count($unconnected);

        return $this->diagram(DiagramType::Component, $chart, [
            'title' => 'Dependency map',
            'caption' => 'The '.$this->countLabel($shown->count(), 'module', 'modules').' with the most links, named by their role'
                .($leftOut > 0 ? ' ('.$this->countLabel($leftOut, 'other linked module is', 'other linked modules are').' left out)' : '')
                .'; an arrow points from the module that uses to the module used, with what the links do and how many there are.',
            'omitted_modules' => $leftOut,
            'unconnected' => $unconnected,
            'nodes' => $this->nodePaths($shown, $ids),
            'findings' => $this->facts->findings($repository, $modules, $shown, $dependencies, $drawnImports, null),
            'evidence' => $this->facts->edgeEvidence($repository, $modules, $dependencies, $drawnModules, $ids),
        ]);
    }

    /**
     * "Context" (C4 level 1), small: the user, the system and the external systems it calls.
     *
     * @param  array<string, mixed>  $facts  SystemFacts
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function contextView(Repository $repository, TechnologyReport $report, array $facts): ?array
    {
        $projects = $this->drawnProjects($report);
        $hasPeople = collect($projects)->contains(fn (DetectedProject $project): bool => $project->first(TechnologyRole::Backend) !== null
            || $project->first(TechnologyRole::Fullstack) !== null
            || $project->first(TechnologyRole::Ui) !== null);
        $outside = [];

        foreach ($report->all() as $technology) {
            if ($technology->role === TechnologyRole::External) {
                $outside['ext_'.$this->externalKey($technology->key)] = ['name' => $technology->name, 'icon' => $technology->icon, 'tool' => false, 'verb' => $technology->key === 'smtp' ? 'Sends email' : 'Calls'];
            }
        }

        // What the code itself shows it calls or starts (URL hosts, SDKs, process calls).
        foreach (array_intersect_key($facts['targets'], $this->drawnTargets($facts)) as $target => $info) {
            [$type, $key] = explode(':', $target, 2);

            if ($key !== 'mail') {
                $id = ($type === 'tool' ? 'tool_' : 'ext_').$this->externalKey($key);
                $outside[$id] ??= [
                    'name' => $info['name'],
                    'icon' => $type === 'tool' ? null : ($key === 'github' ? 'logos:github-icon' : (TechnologyCatalog::TECHNOLOGIES[$key][2] ?? null)),
                    'tool' => $type === 'tool',
                    'verb' => $type === 'tool' ? 'Runs' : 'Calls',
                ];
            }
        }

        if (! $hasPeople && $outside === []) {
            return null;
        }

        $chart = new Flowchart('LR');
        $chart->node('system', MermaidText::label($repository->name, array_values(array_filter([$repository->description])), detailLength: 220), 'system');

        if ($hasPeople) {
            $chart->node('user', MermaidText::label('User', ['Uses the application']), 'person', 'person');
            $chart->edge('user', 'system', MermaidText::escape('Uses'));
        }

        foreach ($outside as $id => $thing) {
            $this->outsideNode($chart, $id, $thing);
            $chart->edge('system', $id, MermaidText::escape($thing['verb']));
        }

        $this->defineC4Styles($chart);
        $externals = array_values(array_filter($outside, fn (array $thing): bool => ! $thing['tool']));

        return $this->diagram(DiagramType::Context, $chart, [
            'title' => 'Context',
            // The external systems drawn, so the reading of the diagram may name them (the page does not list them).
            'externals' => array_map(fn (array $thing): array => ['name' => $thing['name'], 'icon' => $thing['icon']], array_values($outside)),
            'caption' => $outside === []
                ? 'Who uses the system; neither the project files nor the code show external systems or programs it calls.'
                : 'Who uses the system, the external systems it calls'.(count($externals) < count($outside) ? ' and the programs it starts' : '').', from the libraries, settings and code found.',
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
     * The pairs of different layers whose modules are linked, in the order of LAYERS:
     * [from layer, to layer, kind => count]. Every link counts, also those of modules that are not drawn.
     *
     * @param  list<array{from: int, to: int, weight: int, kinds?: array<string, int>}>  $dependencies
     * @param  array<int, string>  $layerOf  module id => layer key
     * @return list<array{string, string, array<string, int>}>
     */
    private function layerDependencies(array $dependencies, array $layerOf): array
    {
        $order = array_flip(array_keys(LayerClassifier::LAYERS));
        $pairs = [];

        foreach ($dependencies as $dependency) {
            $from = $layerOf[$dependency['from']];
            $to = $layerOf[$dependency['to']];

            if ($from === $to) {
                continue;
            }

            $pairs["{$from}→{$to}"] ??= [$from, $to, []];

            foreach ($dependency['kinds'] ?? ['import' => $dependency['weight']] as $kind => $count) {
                $pairs["{$from}→{$to}"][2][$kind] = ($pairs["{$from}→{$to}"][2][$kind] ?? 0) + $count;
            }
        }

        usort($pairs, fn (array $a, array $b): int => [$order[$a[0]], $order[$a[1]]] <=> [$order[$b[0]], $order[$b[1]]]);

        return $pairs;
    }

    /**
     * The words on an arrow of a dependency map: what the links mostly do ("dispatches", "routes to",
     * "renders", "runs"; "uses" for imports and same-namespace classes) and how many links there are.
     *
     * @param  array<string, int>  $kinds
     */
    private function arrowWords(array $kinds): string
    {
        $total = array_sum($kinds);

        foreach (['route', 'dispatch', 'render', 'runs', 'event', 'schedule', 'loads'] as $kind) {
            if (isset($kinds[$kind])) {
                return SystemFacts::verb($kind)." · {$total}";
            }
        }

        return "uses · {$total}";
    }

    /**
     * The role of a module in plain words.
     */
    private function roleOf(string $path): string
    {
        return $this->roles[$path] ?? $this->componentName($path);
    }

    /**
     * The roles of the modules: the label of the big-picture part that holds the module, else the first
     * clause of its documentation page when it says what the module does (at most six words, no code
     * names), else nothing (the folder name is used).
     *
     * @param  Collection<int, Module>  $modules
     * @return array<string, string>
     */
    private function moduleRoles(Repository $repository, Collection $modules): array
    {
        $roles = [];

        foreach (ArchitectureReader::moduleSentences($repository, $modules->pluck('path')->all()) as $path => $sentence) {
            $clause = trim((string) preg_split('/[,;:(—–]| - /u', (string) preg_replace('/^(the|this)\s+(module|folder|package)\s+(\S+\s+)?/i', '', $sentence))[0], ' .');
            $words = preg_split('/\s+/', $clause) ?: [];

            if ($clause !== '' && count($words) <= 6 && ! SystemMapValidator::namesCode($clause) && preg_match('/\b(files?|lines?|fișier|linii)\b/iu', $clause) !== 1) {
                $roles[$path] = mb_strtoupper(mb_substr($clause, 0, 1)).mb_substr($clause, 1);
            }
        }

        // Only a big picture written (and checked) by AI names roles; the fallback's parts are the layers.
        $overview = $repository->diagrams()->where('type', DiagramType::Overview)->first();
        $parts = ($overview?->meta['source'] ?? null) === 'ai' ? $overview->meta['parts'] ?? [] : [];

        foreach ($parts as $part) {
            foreach ($part['modules'] ?? [] as $path) {
                $roles[$path] = $part['label'];
            }
        }

        return $roles;
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

        // One arrow per pair, with the first label given (a pair can be found both in a manifest and in the code).
        foreach ($edges as $edge) {
            $unique["{$edge[0]}→{$edge[1]}"] ??= $edge;
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
