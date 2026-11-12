<?php

namespace App\Http\Resources;

use App\Documentation\DocumentNavigation;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The few facts the Overview's showcase needs that no other prop carries (the RepositoryShowcase type in
 * TypeScript): the „Explore” cards and the README of „Take it with you”. Everything is read from the
 * repository's real rows; a part without data is null (or 0) and the page leaves its card out.
 *
 * - "documentation": how many pages were written and the first three, in the order of the docs menu;
 * - "readme": the download address of the generated README.md and its size in bytes;
 * - "diagram_count": how many diagrams were drawn;
 * - "last_change": what the newest update changed (an update = a run that compared two commits);
 * - "latest_commit": the newest imported commit, for a repository that was never updated.
 *
 * @mixin Repository
 */
class RepositoryShowcaseResource extends JsonResource
{
    /**
     * How many page titles the „Documentation” card lists.
     */
    public const LISTED_PAGES = 3;

    /**
     * Transform the resource into an array.
     *
     * @return array{documentation: array{page_count: int, pages: list<array{title: string, href: string}>, href: string}|null, readme: array{download: string, size_bytes: int}|null, diagram_count: int, last_change: array<string, mixed>|null, latest_commit: array{sha: string, subject: string, authored_at: string}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'documentation' => $this->documentation(),
            'readme' => $this->readme(),
            'diagram_count' => $this->diagrams()->count(),
            'last_change' => $this->lastChange(),
            'latest_commit' => $this->latestCommit(),
        ];
    }

    /**
     * The written documentation pages: their number and the first ones, as the docs menu orders them.
     *
     * @return array{page_count: int, pages: list<array{title: string, href: string}>, href: string}|null
     */
    private function documentation(): ?array
    {
        $pages = DocumentNavigation::for($this->resource);

        if ($pages === []) {
            return null;
        }

        return [
            'page_count' => count($pages),
            'pages' => array_map(
                fn (array $page): array => ['title' => $page['title'], 'href' => $page['href']],
                array_slice($pages, 0, self::LISTED_PAGES),
            ),
            'href' => route('repositories.docs', $this->resource, absolute: false),
        ];
    }

    /**
     * The generated README: where to download it and how big the file is (the download serves the markdown as is).
     *
     * @return array{download: string, size_bytes: int}|null
     */
    private function readme(): ?array
    {
        $readme = Document::whereBelongsTo($this->resource)->where('slug', Document::README)->first(['markdown']);

        if ($readme === null) {
            return null;
        }

        return [
            'download' => route('repositories.docs.readme', $this->resource, absolute: false),
            'size_bytes' => strlen($readme->markdown),
        ];
    }

    /**
     * What the newest successful update changed: the commits it went between and its counters. The first
     * processing compares nothing (no from_sha), so a repository that was never updated has none.
     *
     * @return array{from_sha: string, to_sha: string, finished_at: string|null, files_changed: int|null, modules_affected: int|null, documents_regenerated: int|null}|null
     */
    private function lastChange(): ?array
    {
        $run = $this->processingRuns()
            ->where('status', ProcessingRunStatus::Succeeded)
            ->whereNotNull('from_sha')
            ->whereNotNull('to_sha')
            ->latest('id')
            ->first();

        return $run === null ? null : [
            'from_sha' => $run->from_sha,
            'to_sha' => $run->to_sha,
            'finished_at' => $run->finished_at?->toIso8601String(),
            'files_changed' => $run->files_changed,
            'modules_affected' => $run->modules_affected,
            'documents_regenerated' => $run->documents_regenerated,
        ];
    }

    /**
     * The newest imported commit (by author date), or null before the history is imported.
     *
     * @return array{sha: string, subject: string, authored_at: string}|null
     */
    private function latestCommit(): ?array
    {
        $commit = $this->commits()->latest('authored_at')->first(['sha', 'subject', 'authored_at']);

        return $commit === null ? null : [
            'sha' => $commit->sha,
            'subject' => $commit->subject,
            'authored_at' => $commit->authored_at->toIso8601String(),
        ];
    }
}
