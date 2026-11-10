<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Documentație” tab of a repository: the overview page and one page per module, written from the code (Faza 6).
 * Until that part is built, the page only says what will appear here.
 */
class DocsController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the tab to anyone: repositories are public.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/docs');
    }
}
