<?php

namespace App\Http\Controllers\Repository;

use App\Documentation\DocumentNavigation;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Documentație” tab of a repository (Faza 6): the chapter list and one page, the overview by default.
 * /repositories/{id}/docs/{slug} opens another page; a slug the repository does not have gives 404.
 * Without a slug and before the overview exists, the page shows its empty state (still being written,
 * failed, or not written yet).
 */
class DocsController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the tab to anyone: repositories are public.
     *
     * "navigation" and "document" are closures, so the polling of RepositoryLayout (which asks only for
     * "repository" and "can_retry") does not run their queries every 2 seconds.
     */
    public function __invoke(Request $request, Repository $repository, ?string $slug = null): Response
    {
        $document = Document::whereBelongsTo($repository)
            ->where('slug', $slug ?? Document::OVERVIEW)
            ->first();

        if ($slug !== null && $document === null) {
            abort(404);
        }

        $document?->setRelation('repository', $repository);

        return $this->renderRepositoryPage($request, $repository, 'repositories/docs', [
            'navigation' => fn () => DocumentNavigation::for($repository),
            'document' => fn () => $document === null ? null : DocumentResource::make($document)->resolve(),
        ]);
    }
}
