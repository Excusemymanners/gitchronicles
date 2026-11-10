<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Istoric” tab of a repository: the commits by month, the processing runs and the time travel to older documentation (Faza 9).
 * Until that part is built, the page only says what will appear here.
 */
class TimelineController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the tab to anyone: repositories are public.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/timeline');
    }
}
