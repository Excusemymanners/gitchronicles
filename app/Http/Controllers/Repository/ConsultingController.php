<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultingReportResource;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Consultanță” page of a repository: the newest report made by Semgrep, OSV-Scanner and scc
 * (GenerateConsultingReport), or nothing yet. Public, like every page of a repository; only a logged-in
 * user gets „Rulează analiza” (can_run_consulting, RepositoryPolicy::runConsulting).
 *
 * "consulting" is a closure: while the analysis is working the page asks only for it (and for
 * can_run_consulting) every few seconds, and the workspace query does not run.
 */
class ConsultingController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the page to anyone: repositories are public.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/consulting', [
            'consulting' => function () use ($repository): ?array {
                $report = $repository->consultingReports()->latest('id')->first();

                return $report === null ? null : ConsultingReportResource::make($report->setRelation('repository', $repository))->resolve();
            },
            'can_run_consulting' => fn (): bool => $request->user()?->can('runConsulting', $repository) ?? false,
        ]);
    }
}
