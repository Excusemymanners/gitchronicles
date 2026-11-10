<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Video” tab of a repository: the presentation video and the slides made from the generated data (bonus).
 * Until that part is built, the page only says what will appear here.
 */
class MediaController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the tab to anyone: repositories are public.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/media');
    }
}
