<?php

namespace App\Documentation;

use App\Enums\DiagramType;
use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Media\GitHubCapture;
use App\Models\Diagram;
use App\Models\Document;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The pictures a documentation page shows around its stored text, as data for the page components. Nothing here
 * is written by a text generator and the Markdown is never changed:
 *
 * - from the repository's README (ReadmeShowcase): "pictures", each with its alt text and the README section it was in;
 * - from the capture of the repository's GitHub page (GitHubCapture), when one exists: "capture";
 * - drawn from data we already have: "facts" (languages and numbers), "architecture" (the stored diagram),
 *   "neighbourhood" (a module and the modules around it, from module_dependencies) and "updates" (the processing runs).
 *
 * A picture is included only when its source exists. The parts that need the database are kept in the cache per
 * repository, page and commit (and run), so opening a page again costs no queries for them.
 */
final class DocumentVisuals
{
    /**
     * At most this many neighbours are drawn on each side of a module; the rest is summarised as "+ N more".
     */
    public const MAX_NEIGHBOURS = 6;

    /**
     * The architecture diagram shown inside the text: the first type that exists, in this order (the big
     * picture, "How <name> works", first).
     */
    private const ARCHITECTURE_TYPES = [DiagramType::Overview, DiagramType::Component, DiagramType::Layers, DiagramType::Container, DiagramType::Context];

    /**
     * The mini timeline shows this many of the latest updates.
     */
    private const MAX_UPDATES = 5;

    /**
     * The overview shows this many sections of the README's own description, collapsed.
     */
    public const OVERVIEW_SECTIONS = 2;

    public function __construct(private ReadmeShowcase $showcase, private GitHubCapture $capture) {}

    /**
     * The pictures of one page.
     *
     * @return array{commit: ?string, capture: ?array<string, mixed>, pictures: list<array<string, mixed>>, gallery: list<string>, description: ?array<string, mixed>, facts: ?array<string, mixed>, architecture: ?array<string, mixed>, neighbourhood: ?array<string, mixed>, updates: list<array<string, mixed>>}
     */
    public function for(Repository $repository, Document $document): array
    {
        $isOverview = $document->slug === Document::OVERVIEW;
        $isReadme = $document->slug === Document::README;
        $drawn = $this->drawn($repository, $document, $isOverview, $isReadme);

        $description = $isOverview || $isReadme ? $this->description($repository, $document) : null;
        $pictures = $isOverview || $isReadme ? $this->pictures($repository) : [];
        $used = array_merge(...array_column($description['sections'] ?? [], 'pictures') ?: [[]]);

        return [
            'commit' => $repository->last_sha,
            'capture' => $isOverview || $isReadme ? $this->captureOf($repository) : null,
            'pictures' => $pictures,
            // The README's pictures that no shown description section already holds: the gallery.
            'gallery' => array_values(array_diff(array_column($pictures, 'id'), $used)),
            'description' => $description,
            ...$drawn,
        ];
    }

    /**
     * The README's own description (ReadmeDescription) for the overview (the first two sections, the rest is in the
     * generated README) or the generated README (all of them); null when there is none.
     *
     * @return array{sections: list<array{heading: ?string, level: int, markdown: string, pictures: list<string>}>, total: int, readme_href: string}|null
     */
    public function description(Repository $repository, Document $document): ?array
    {
        $manifest = $this->showcase->manifest($repository);

        if ($manifest === null) {
            return null;
        }

        $suffix = '?v='.substr((string) $repository->last_sha, 0, 7);
        $sections = ReadmeDescription::sections($manifest, $document->markdown, fn (string $file, ?int $width, ?int $height): string => route('repositories.docs.picture', [$repository, pathinfo($file, PATHINFO_FILENAME)], absolute: false)
            .$suffix.($width && $height ? "#{$width}x{$height}" : ''));

        if ($sections === []) {
            return null;
        }

        return [
            'sections' => $document->slug === Document::OVERVIEW ? array_slice($sections, 0, self::OVERVIEW_SECTIONS) : $sections,
            'total' => count($sections),
            'readme_href' => route('repositories.docs', [$repository, Document::README], absolute: false),
        ];
    }

    /**
     * The pictures of the README of the repository, with the address the app serves each one at.
     *
     * @return list<array{id: string, url: string, width: ?int, height: ?int, alt: string, section: ?string}>
     */
    public function pictures(Repository $repository): array
    {
        $manifest = $this->showcase->manifest($repository);

        return array_map(fn (array $picture): array => [
            'id' => $picture['id'],
            'url' => route('repositories.docs.picture', [$repository, $picture['id']], absolute: false).'?v='.substr((string) $repository->last_sha, 0, 7),
            'width' => $picture['width'],
            'height' => $picture['height'],
            'alt' => $picture['alt'],
            'section' => $picture['section'],
        ], $manifest['pictures'] ?? []);
    }

    /**
     * The opening picture: the first tile of the stored capture of the GitHub page, or null when there is none.
     *
     * @return array{url: string, width: int, height: int, captured_at: ?string}|null
     */
    public function captureOf(Repository $repository): ?array
    {
        $file = $this->captureFile($repository);

        if ($file === null) {
            return null;
        }

        return [
            'url' => route('repositories.docs.picture', [$repository, 'github-page'], absolute: false).'?v='.substr((string) $repository->last_sha, 0, 7),
            'width' => $file['width'],
            'height' => $file['height'],
            'captured_at' => $file['captured_at'],
        ];
    }

    /**
     * The file of the opening picture (the top of the capture) and its size.
     *
     * @return array{path: string, width: int, height: int, captured_at: ?string}|null
     */
    public function captureFile(Repository $repository): ?array
    {
        $capture = $this->capture->stored($repository);

        if (! $capture->isAvailable()) {
            return null;
        }

        $tile = $capture->data['tiles'][0]['file'] ?? null;
        $path = is_string($tile) ? $capture->directory.'/'.basename($tile) : null;
        $size = $path !== null && is_file($path) && ! is_link($path) ? @getimagesize($path) : false;

        if (! is_array($size) || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return null;
        }

        return ['path' => $path, 'width' => $size[0], 'height' => $size[1], 'captured_at' => $capture->capturedAt()];
    }

    /**
     * The parts drawn from the database, cached per repository, page, commit and last run.
     *
     * @return array{facts: ?array<string, mixed>, architecture: ?array<string, mixed>, neighbourhood: ?array<string, mixed>, updates: list<array<string, mixed>>}
     */
    private function drawn(Repository $repository, Document $document, bool $isOverview, bool $isReadme): array
    {
        $key = implode(':', ['docs-visuals', $repository->id, $document->id, $repository->last_sha, $document->updated_at?->timestamp, $repository->updated_at?->timestamp]);

        return Cache::remember($key, now()->addDay(), fn (): array => [
            'facts' => $isOverview ? $this->facts($repository) : null,
            'architecture' => $isOverview || $isReadme ? $this->architecture($repository) : null,
            'neighbourhood' => $document->module_id !== null ? $this->neighbourhood($repository, $document->module_id) : null,
            'updates' => $isOverview ? $this->updates($repository) : [],
        ]);
    }

    /**
     * The languages (share of the lines) and the key numbers.
     *
     * @return array{languages: list<array{name: string, share: float}>, files: int, lines: int, modules: int, commits: int}|null
     */
    public function facts(Repository $repository): ?array
    {
        $languages = $repository->files()->toBase()
            ->selectRaw('language, count(*) as file_count, sum(lines) as line_count')
            ->groupBy('language')
            ->get();

        $lines = (int) $languages->sum('line_count');

        if ($languages->isEmpty()) {
            return null;
        }

        // Two counts in one query: the page is opened once per commit, so every query counts.
        $counts = DB::selectOne(
            'select (select count(*) from modules where repository_id = ?) as modules, (select count(*) from commits where repository_id = ?) as commits',
            [$repository->id, $repository->id],
        );

        return [
            'languages' => $languages->whereNotNull('language')->sortByDesc('line_count')->take(6)->values()
                ->map(fn (object $language): array => ['name' => $language->language, 'share' => $lines > 0 ? round($language->line_count / $lines, 4) : 0.0])
                ->all(),
            'files' => (int) $languages->sum('file_count'),
            'lines' => $lines,
            'modules' => (int) $counts->modules,
            'commits' => (int) $counts->commits,
        ];
    }

    /**
     * The stored architecture diagram that fits the text best, with the address of the Diagrams page.
     *
     * @return array{type: string, title: string, mermaid: string, caption: ?string, href: string}|null
     */
    public function architecture(Repository $repository): ?array
    {
        $diagrams = Diagram::where('repository_id', $repository->id)->whereIn('type', array_column(self::ARCHITECTURE_TYPES, 'value'))->get();

        foreach (self::ARCHITECTURE_TYPES as $type) {
            $diagram = $diagrams->first(fn (Diagram $candidate): bool => $candidate->type === $type);

            if ($diagram !== null && trim($diagram->mermaid) !== '') {
                return [
                    'type' => $type->value,
                    'title' => (string) ($diagram->meta['title'] ?? $type->value),
                    'mermaid' => $diagram->mermaid,
                    'caption' => $diagram->meta['caption'] ?? null,
                    'href' => route('repositories.diagrams', $repository, absolute: false),
                ];
            }
        }

        return null;
    }

    /**
     * A module and the modules around it: who imports it (left) and whom it imports (right), with the number of
     * imports, at most MAX_NEIGHBOURS a side; the rest is only counted ("more").
     *
     * @return array{module: string, incoming: list<array{path: string, weight: int, href: ?string}>, outgoing: list<array{path: string, weight: int, href: ?string}>, more_incoming: int, more_outgoing: int}|null
     */
    private function neighbourhood(Repository $repository, int $moduleId): ?array
    {
        $module = Module::find($moduleId);

        if ($module === null) {
            return null;
        }

        $incoming = ModuleDependency::where('to_module_id', $moduleId)->where('from_module_id', '!=', $moduleId)->orderByDesc('weight')->orderBy('id')->get(['from_module_id as module_id', 'weight']);
        $outgoing = ModuleDependency::where('from_module_id', $moduleId)->where('to_module_id', '!=', $moduleId)->orderByDesc('weight')->orderBy('id')->get(['to_module_id as module_id', 'weight']);

        if ($incoming->isEmpty() && $outgoing->isEmpty()) {
            return null;
        }

        $shown = $incoming->take(self::MAX_NEIGHBOURS)->concat($outgoing->take(self::MAX_NEIGHBOURS))->pluck('module_id')->unique()->all();
        $paths = Module::whereIn('id', $shown)->pluck('path', 'id');
        $slugs = Document::where('repository_id', $repository->id)->whereIn('module_id', $shown)->pluck('slug', 'module_id');
        $side = fn ($rows) => $rows->take(self::MAX_NEIGHBOURS)->map(fn ($row): array => [
            'path' => (string) $paths[$row->module_id],
            'weight' => (int) $row->weight,
            'href' => isset($slugs[$row->module_id]) ? route('repositories.docs', [$repository, $slugs[$row->module_id]], absolute: false) : null,
        ])->values()->all();

        return [
            'module' => $module->path,
            'incoming' => $side($incoming),
            'outgoing' => $side($outgoing),
            'more_incoming' => max(0, $incoming->count() - self::MAX_NEIGHBOURS),
            'more_outgoing' => max(0, $outgoing->count() - self::MAX_NEIGHBOURS),
        ];
    }

    /**
     * How a change travelled in the latest updates: the commit range of a run and the number of pages it rewrote.
     * Empty until the repository was updated at least once after its first processing.
     *
     * @return list<array{from_sha: ?string, to_sha: ?string, files_changed: int, documents_regenerated: int, documents_kept: int, finished_at: ?string}>
     */
    private function updates(Repository $repository): array
    {
        return ProcessingRun::where('repository_id', $repository->id)
            ->where('status', ProcessingRunStatus::Succeeded)
            ->where('mode', ProcessingMode::Incremental)
            ->whereNotNull('to_sha')
            ->orderByDesc('id')
            ->limit(self::MAX_UPDATES)
            ->get()
            ->reverse()
            ->map(fn (ProcessingRun $run): array => [
                'from_sha' => $run->from_sha,
                'to_sha' => $run->to_sha,
                'files_changed' => (int) $run->files_changed,
                'documents_regenerated' => (int) $run->documents_regenerated,
                'documents_kept' => (int) $run->documents_kept,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->values()->all();
    }
}
