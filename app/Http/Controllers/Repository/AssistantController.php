<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Asistent” tab of a repository: the chat that answers questions about the code and cites files and lines (Faza 8).
 * The conversation itself lives in the workspace (WorkspaceLayout), so this page needs only the shared props;
 * the questions go to AssistantQuestionController.
 */
class AssistantController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the tab to anyone: repositories are public.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/assistant');
    }
}
