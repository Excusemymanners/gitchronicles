<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Diagrame” tab of a repository: the C4 architecture diagrams built from the code analysis (Faza 7).
 * Until that part is built, the page only says what will appear here.
 */
class DiagramsController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the tab to anyone: repositories are public.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/diagrams');
    }
}
