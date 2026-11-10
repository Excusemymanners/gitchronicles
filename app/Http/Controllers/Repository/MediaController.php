<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Http\Resources\PresentationMediaResource;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Video” page of a repository: the presentation video (with its scenes and the source of every
 * sentence) and the slides, made on the "media" queue after the repository is ready (GenerateMedia).
 *
 * "media" is a closure: the page asks only for it every few seconds while something is being rendered,
 * and the layout's own polling (which asks for "repository" and "can_retry") does not run its query.
 */
class MediaController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the page to anyone: repositories are public.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/media', [
            'media' => fn () => PresentationMediaResource::make($repository)->resolve(),
        ]);
    }
}
