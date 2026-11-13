<?php

namespace App\Http\Controllers\Repository;

use App\Documentation\DocumentNavigation;
use App\Documentation\DocumentVisuals;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Incremental\ProcessedCommits;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The „Documentație” tab of a repository (Faza 6): the chapter list and one page, the overview by default.
 * /repositories/{id}/docs/{slug} opens another page; a slug the repository does not have gives 404.
 * Without a slug and before the overview exists, the page shows its empty state (still being written,
 * failed, or not written yet).
 *
 * With "?sha=" of a processed commit the page travels in time (Faza 9): "time_travel.version" is the
 * page's text as it was right after that commit's run (its newest DocumentVersion written by that run
 * or an older one, see ProcessedCommits), or null when the page did not exist yet.
 */
class DocsController extends Controller
{
    use RendersRepositoryPage;
    use TravelsInTime;

    /**
     * Show the tab to anyone: repositories are public.
     *
     * "navigation" is an Inertia once prop (the browser keeps the chapter list while moving from page to page,
     * and gets it again when WorkspaceVersion changes); "document", "visuals" and "time_travel" are closures, so the polling of RepositoryLayout (which
     * asks only for "repository" and "can_retry") does not run their queries every 2 seconds.
     */
    public function __invoke(Request $request, Repository $repository, ProcessedCommits $commits, DocumentVisuals $visuals, ?string $slug = null): Response
    {
        $document = Document::whereBelongsTo($repository)
            ->where('slug', $slug ?? Document::OVERVIEW)
            ->first();

        if ($slug !== null && $document === null) {
            abort(404);
        }

        $document?->setRelation('repository', $repository);
        $chosenRun = $this->chosenRun($request, $repository, $commits);

        return $this->renderRepositoryPage($request, $repository, 'repositories/docs', [
            'navigation' => Inertia::once(fn () => DocumentNavigation::for($repository))->as('navigation:'.$this->workspaceVersionOf($request, $repository)),
            'document' => fn () => $document === null ? null : DocumentResource::make($document)->resolve(),
            // The pictures around the text (README pictures, diagrams, facts), cached per page and commit.
            'visuals' => fn () => $document === null || $chosenRun !== null ? null : $visuals->for($repository, $document),
            'time_travel' => fn () => [
                'commits' => $commits->of($repository),
                'selected' => $chosenRun?->to_sha,
                'version' => $chosenRun === null || $document === null ? null : $this->versionAt($document, $chosenRun),
            ],
        ]);
    }

    /**
     * The page as it was right after the chosen commit's run: its newest version written by that run or
     * an older one. "same_as_current" tells the reader the page has not changed since.
     *
     * @return array{sha: string|null, markdown: string, written_at: string|null, same_as_current: bool}|null
     */
    private function versionAt(Document $document, ProcessingRun $run): ?array
    {
        $version = $document->versions()->where('processing_run_id', '<=', $run->id)->reorder()->latest('id')->first();

        if ($version === null) {
            return null;
        }

        return [
            'sha' => $version->sha,
            'markdown' => $version->markdown,
            'written_at' => $version->created_at?->toIso8601String(),
            'same_as_current' => $version->markdown === $document->markdown,
        ];
    }
}
