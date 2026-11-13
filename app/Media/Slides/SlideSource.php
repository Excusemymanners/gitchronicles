<?php

namespace App\Media\Slides;

use App\Diagrams\CodeOverview;
use App\Enums\DiagramType;
use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Git\HistoryStatistics;
use App\Models\Document;
use App\Models\Repository;
use Illuminate\Support\Collection;

/**
 * Reads what the deck is made from: the documentation pages and the diagrams already stored for the
 * repository (not a second analysis), the most changed files and the latest commits.
 */
final class SlideSource
{
    public const MAX_MODULE_PAGES = 6;

    /**
     * Folders that hold setup or data code, never a part of the application a slide should present.
     */
    private const DATA_FOLDERS = ['migrations', 'migration', 'factories', 'seeders', 'seeds', 'fixtures', 'stubs'];

    public const MAX_FLOWS = 2;

    public function __construct(private HistoryStatistics $history, private CodeOverview $overview) {}

    /**
     * Everything the planner needs from the database.
     *
     * @return array{overview: ?string, readme: ?string, modules: list<array{path: string, lines: int, files: int, writer: string, markdown: string}>, diagrams: list<array{kind: string, title: string, caption: ?string, explanation: ?string, mermaid: string}>, hotspots: list<array{path: string, commit_count: int}>, recent: ?array{commits: list<array{subject: string, date: string}>}}
     */
    public function collect(Repository $repository): array
    {
        $pages = Document::whereBelongsTo($repository)->with('module:id,path,line_count,file_count')->get();

        return [
            'overview' => $pages->firstWhere('slug', Document::OVERVIEW)?->markdown,
            'readme' => $pages->firstWhere('slug', Document::README)?->markdown,
            'modules' => $this->modules($repository, $pages),
            'diagrams' => $this->diagrams($repository),
            'hotspots' => array_map(
                fn (array $hotspot): array => ['path' => $hotspot['path'], 'commit_count' => $hotspot['commit_count']],
                $this->history->hotspots($repository, 4),
            ),
            'recent' => $this->recent($repository),
        ];
    }

    /**
     * The module pages of the application's biggest modules: the modules the diagram builder counts as the
     * running application (CodeOverview), without migrations, factories and seeders.
     *
     * @param  Collection<int, Document>  $pages
     * @return list<array{path: string, lines: int, files: int, writer: string, markdown: string}>
     */
    private function modules(Repository $repository, $pages): array
    {
        [$application] = $this->overview->applicationModules($repository);
        $allowed = $application
            ->filter(fn ($module): bool => array_intersect(explode('/', strtolower($module->path)), self::DATA_FOLDERS) === [])
            ->pluck('id')
            ->all();

        return $pages
            ->filter(fn (Document $page): bool => $page->module !== null && in_array($page->module->id, $allowed, true))
            ->sortBy([fn (Document $a, Document $b): int => $b->module->line_count <=> $a->module->line_count, fn (Document $a, Document $b): int => strcmp($a->module->path, $b->module->path)])
            ->take(self::MAX_MODULE_PAGES)
            ->map(fn (Document $page): array => [
                'path' => $page->module->path,
                'lines' => (int) $page->module->line_count,
                'files' => (int) $page->module->file_count,
                'writer' => $page->writer,
                'markdown' => $page->markdown,
            ])
            ->values()
            ->all();
    }

    /**
     * The architecture diagrams (context, container, component; the layers diagram only when there is no
     * C4 diagram) followed by the flows, as Mermaid with their titles and explanations.
     *
     * @return list<array{kind: string, title: string, caption: ?string, explanation: ?string, mermaid: string}>
     */
    private function diagrams(Repository $repository): array
    {
        $all = $repository->diagrams()->get();
        $architecture = [];

        // The big picture first ("how it is built"), then the C4 views.
        foreach ([DiagramType::Overview, DiagramType::Context, DiagramType::Container, DiagramType::Component] as $type) {
            $diagram = $all->firstWhere('type', $type);

            if ($diagram !== null) {
                $architecture[] = $diagram;
            }
        }

        if ($architecture === [] && $all->firstWhere('type', DiagramType::Layers) !== null) {
            $architecture[] = $all->firstWhere('type', DiagramType::Layers);
        }

        // The flows in the order they were written: the main flow first.
        $flows = $all->where('type', DiagramType::Flow)->sortBy('id')->take(self::MAX_FLOWS);

        return $all->isEmpty() ? [] : collect([...$architecture, ...$flows])->map(fn ($diagram): array => [
            'kind' => $diagram->type === DiagramType::Flow ? 'flow' : 'architecture',
            'title' => (string) ($diagram->meta['title'] ?? $diagram->type->value),
            'caption' => $diagram->meta['caption'] ?? null,
            'explanation' => $diagram->meta['explanation'] ?? null,
            'mermaid' => $diagram->mermaid,
        ])->values()->all();
    }

    /**
     * The latest commits, only for a repository that has been updated after its first processing.
     *
     * @return array{commits: list<array{subject: string, date: string}>}|null
     */
    private function recent(Repository $repository): ?array
    {
        $isUpdated = $repository->processingRuns()
            ->where('status', ProcessingRunStatus::Succeeded)
            ->where('mode', '!=', ProcessingMode::Initial)
            ->exists();

        if (! $isUpdated) {
            return null;
        }

        $commits = $repository->commits()->where('is_merge', false)->orderByDesc('authored_at')->limit(5)->get(['subject', 'authored_at']);

        return $commits->isEmpty() ? null : ['commits' => $commits->map(fn ($commit): array => ['subject' => (string) $commit->subject, 'date' => $commit->authored_at->toDateString()])->all()];
    }
}
