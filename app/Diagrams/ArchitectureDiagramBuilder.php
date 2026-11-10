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
 * 1. „Arhitectura pe straturi”: the modules with code, grouped in layers by folder names (LayerClassifier),
 *    with the imports between them; the left-out folders are listed under the diagram.
 * 2. „Containere” (C4 level 2): the parts that run separately (the web application, the pages in the
 *    browser, a queue worker, the database, ...) with their technology logos, inside the system boundary,
 *    and the external services the code talks to.
 * 3. „Componente” (C4 level 3): the 15 biggest modules with their main classes and the strongest
 *    25 dependencies; the other modules are counted in one „+ N alte module” box.
 * 4. „Context” (C4 level 1): who uses the system and which external systems it talks to.
 *
 * A view without data is not drawn at all (a repository with only a README gets no diagrams).
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
            $this->layersView($modules, $dependencies, $excluded),
            $this->containerView($repository, $report),
            $this->componentView($repository, $modules, $dependencies),
            $this->contextView($repository, $report),
        ]));
    }

    /**
     * „Arhitectura pe straturi”: one group per layer, from the entry points down to the data, with the
     * biggest modules of each layer as a grid of boxes, and one arrow from a layer to another when
     * modules of the first import code from the second (no numbers: the details are in „Componente”).
     *
     * @param  Collection<int, Module>  $modules
     * @param  list<array{from: int, to: int, weight: int}>  $dependencies
     * @param  list<array{label: string, paths: list<string>}>  $excluded
     * @return array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}|null
     */
    private function layersView(Collection $modules, array $dependencies, array $excluded): ?array
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
                $chart->node("more_{$layer}", MermaidText::escape('+ '.$this->countLabel($hiddenCount, 'alt modul', 'alte module')), 'more');
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

        foreach ($this->layerDependencies($dependencies, $layerOf->all()) as [$fromLayer, $toLayer]) {
            $chart->edge("layer_{$fromLayer}", "layer_{$toLayer}");
        }

        foreach (self::LAYER_STYLES as $layer => $style) {
            $chart->classDef("layer_{$layer}", $style);
        }

        $chart->classDef('more', self::C4_STYLES['more']);

        $caption = ($shown->count() < $modules->count()
            ? "Cele mai mari {$shown->count()} din cele {$this->countLabel($modules->count(), 'modul', 'module')} cu cod, grupate pe straturi după numele folderelor"
            : 'Modulele cu cod ale proiectului ('.$this->countLabel($modules->count(), 'modul', 'module').'), grupate pe straturi după numele folderelor')
            .'; o săgeată arată că modulele unui strat importă cod din celălalt strat.';

        return $this->diagram(DiagramType::Layers, $chart, [
            'title' => 'Arhitectura pe straturi',
            'caption' => $caption,
            'excluded' => $excluded,
        ]);
    }

    /**
     * „Containere” (C4 level 2): what runs separately, with the technology that proves it.
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
                $title = $this->projectTitle($repository, $project, $server === null ? null : 'Aplicația web');
                $details = $this->joinNames([$server, ...$serverLanguages]);
                $description = match (true) {
                    $server?->role === TechnologyRole::Fullstack => 'Serverul și paginile',
                    $server !== null => 'Serverul web',
                    // Without a framework, the tools describe the project, unless they belong to its pages (Vite).
                    $pages === null => $this->toolsLine($project),
                    default => null,
                };
                $this->container($chart, $serverId, $server ?? $serverLanguages[0] ?? null, $title, array_filter([$details, $description]));
            }

            if ($pages !== null) {
                $pagesId = "{$prefix}_ui";
                $title = $this->projectTitle($repository, $project, 'Interfața web', $serverId !== null ? 'interfața' : null);
                $details = $this->joinNames([$pages, $this->pagesLanguage($project)]);
                $this->container($chart, $pagesId, $pages, $title, [$details, 'Paginile, în browser']);

                if ($serverId !== null) {
                    $edges[] = [$pagesId, $serverId, $project->uses('inertia') ? 'Cereri Inertia · HTTP' : 'Cereri HTTP'];
                }
            }

            $mainId = $serverId ?? $pagesId;

            if ($server !== null || $pages !== null) {
                // Only the first arrow from the user is labeled: the others would repeat the same words.
                $edges[] = ['user', $pagesId ?? $serverId, $hasPeople ? '' : 'Folosește · HTTPS'];
                $hasPeople = true;
            }

            $workerId = null;

            if ($project->hasQueueJobs && $project->uses('laravel')) {
                $workerId = "{$prefix}_worker";
                $this->container($chart, $workerId, $project->first(TechnologyRole::Backend), 'Workerul de cozi', ['Laravel · queue:work', 'Rulează joburile în fundal']);
            }

            foreach ([...$project->withRole(TechnologyRole::Database), ...$project->withRole(TechnologyRole::Cache)] as $store) {
                $stores[$store->key] ??= $store;
                $edges[] = [$mainId, "store_{$store->key}", $store->role === TechnologyRole::Database ? 'Citește și scrie' : $this->cacheUsage($project, $store)];

                if ($workerId !== null && $this->queueStore($project) === $store->key) {
                    $edges[] = [$workerId, "store_{$store->key}", 'Preia joburile'];
                }
            }

            foreach ($project->withRole(TechnologyRole::External) as $external) {
                $externals[$external->key] ??= $external;
                $edges[] = [$mainId, "ext_{$external->key}", $external->key === 'smtp' ? 'Trimite e-mail · SMTP' : 'API · HTTPS'];
            }
        }

        // Servers started by docker-compose or Kubernetes next to the code, even without an arrow.
        foreach ($report->shared as $technology) {
            if (in_array($technology->role, [TechnologyRole::Database, TechnologyRole::Cache], true)) {
                $stores[$technology->key] ??= $technology;
            }
        }

        foreach ($stores as $key => $store) {
            $title = $store->role === TechnologyRole::Database ? 'Baza de date' : $store->name;
            $details = $store->role === TechnologyRole::Database ? [$store->name] : [];
            $this->container($chart, "store_{$key}", $store, $title, $details, cylinder: $store->role === TechnologyRole::Database);
        }

        $hiddenProjects = count($report->projects) - count($projects);

        if ($hiddenProjects > 0) {
            $chart->node('more', MermaidText::escape('+ '.$this->countLabel($hiddenProjects, 'alt pachet', 'alte pachete')), 'more');
        }

        $chart->endGroup();

        if ($hasPeople) {
            $chart->node('user', MermaidText::label('Utilizator', ['Folosește aplicația']), 'person', 'person');
        }

        foreach ($externals as $key => $external) {
            $this->external($chart, "ext_{$key}", $external);
        }

        foreach ($this->uniqueEdges($edges) as [$from, $to, $label]) {
            $chart->edge($from, $to, $label === '' ? null : MermaidText::escape($label));
        }

        $this->defineC4Styles($chart);

        return $this->diagram(DiagramType::Container, $chart, [
            'title' => 'Containere',
            'caption' => 'Părțile care rulează separat și tehnologiile lor, găsite în '.$this->sourcesSentence($report).'; o săgeată arată cine pe cine apelează.',
            'technologies' => array_map(fn (Technology $technology): array => $technology->toArray(), $report->all()),
            'hidden_projects' => $hiddenProjects,
        ]);
    }

    /**
     * „Componente” (C4 level 3): the biggest modules with their main classes, inside the system boundary.
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
        $chart->startGroup('system', '<b>'.MermaidText::escape($repository->name).'</b> · componentele codului', 'boundary');

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
            $chart->node('more', MermaidText::escape('+ '.$this->countLabel($omitted, 'alt modul', 'alte module')), 'more');
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

        $possibleEdges = count(array_filter($dependencies, fn (array $dependency): bool => isset($ids[$dependency['from']], $ids[$dependency['to']])));

        $caption = ($omitted > 0
            ? "Cele mai mari {$shown->count()} din cele {$this->countLabel($modules->count(), 'modul', 'module')} cu cod, cu clasele lor principale"
            : 'Modulele cu cod ale proiectului, cu clasele lor principale')
            .match (true) {
                $drawnEdges === [] => '; nu se importă între ele.',
                count($drawnEdges) < $possibleEdges => ' și cele mai puternice '.$this->countLabel(count($drawnEdges), 'dependență', 'dependențe').'; săgeata arată cine pe cine importă.',
                default => '; săgeata arată că un modul importă cod din altul.',
            };

        return $this->diagram(DiagramType::Component, $chart, [
            'title' => 'Componente',
            'caption' => $caption,
            'omitted_modules' => $omitted,
        ]);
    }

    /**
     * „Context” (C4 level 1), small: the user, the system and the external systems it calls.
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
            $chart->node('user', MermaidText::label('Utilizator', ['Folosește aplicația']), 'person', 'person');
            $chart->edge('user', 'system', MermaidText::escape('Folosește'));
        }

        foreach ($externals as $external) {
            $this->external($chart, "ext_{$external->key}", $external);
            $chart->edge('system', "ext_{$external->key}", MermaidText::escape($external->key === 'smtp' ? 'Trimite e-mail' : 'Trimite cereri'));
        }

        $this->defineC4Styles($chart);

        return $this->diagram(DiagramType::Context, $chart, [
            'title' => 'Context',
            'caption' => $externals === []
                ? 'Cine folosește sistemul; în fișierele proiectului nu am găsit sisteme externe apelate de cod.'
                : 'Cine folosește sistemul și ce sisteme externe apelează codul (după bibliotecile și setările găsite).',
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
        $label = MermaidText::label($technology->name, ['Sistem extern']);

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
     * The title of a project's container: the generic name for the root project ("Aplicația web"),
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
            'Sesiuni' => [$project->environment['SESSION_DRIVER'] ?? null],
            'Coada de joburi' => [$project->environment['QUEUE_CONNECTION'] ?? null],
        ];

        $uses = [];

        foreach ($driverKeys as $use => [$driver]) {
            if ($driver !== null && (TechnologyCatalog::CACHE_STORES[$driver] ?? TechnologyCatalog::QUEUE_CONNECTIONS[$driver] ?? null) === $store->key) {
                $uses[] = $use;
            }
        }

        return $uses === [] ? 'Folosește' : implode(' · ', $uses);
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
     * Where the technologies were found, as a Romanian list: "composer.json, package.json și .env.example".
     */
    private function sourcesSentence(TechnologyReport $report): string
    {
        $sources = array_values(array_unique(array_map(fn (Technology $technology): string => basename($technology->source), $report->all())));

        if (count($sources) <= 1) {
            return $sources[0] ?? 'fișierele proiectului';
        }

        return implode(', ', array_slice($sources, 0, -1)).' și '.end($sources);
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
     * A number with its noun, in Romanian: "1 modul", "16 module", "20 de module".
     */
    private function countLabel(int $number, string $one, string $many): string
    {
        if ($number === 1) {
            return "1 {$one}";
        }

        $needsDe = $number % 100 === 0 || $number % 100 >= 20;

        return $number.($needsDe ? ' de ' : ' ').$many;
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
