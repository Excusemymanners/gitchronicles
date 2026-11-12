<?php

namespace App\Http\Controllers\Repository;

use App\Assistant\AnswerQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskAssistantRequest;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;

/**
 * POST /repositories/{repository}/assistant: one question to the assistant (Faza 8), answered as JSON
 * (the chat sends it with Inertia's useHttp, not as a page visit):
 *
 *     {answer_markdown, citations: [{index, path, start_line, end_line, symbol_name, url, cited}],
 *      mode: "ai" | "retrieval", notice, usage}
 *
 * Public, like every repository page, and limited to 20 questions per hour for each IP address
 * (the "assistant" rate limiter in AppServiceProvider).
 */
class AssistantQuestionController extends Controller
{
    /**
     * Answer the question, or 409 when the repository's code has not been indexed yet.
     */
    public function __invoke(AskAssistantRequest $request, Repository $repository, AnswerQuestion $answerQuestion): JsonResponse
    {
        abort_unless(
            $repository->codeChunks()->exists(),
            409,
            __('assistant.not_indexed'),
        );

        return response()->json($answerQuestion->handle(
            $repository,
            $request->validated('question'),
            $request->validated('history', []),
        ));
    }
}
