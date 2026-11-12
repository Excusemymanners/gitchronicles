<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateConsultingReport;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * „Rulează analiza” / „Rulează din nou” on the „Consultanță” page (RepositoryPolicy::runConsulting):
 * queue a new analysis of the current commit, even when it already has a report, and go back to the page,
 * which then follows it.
 */
class RunConsultingController extends Controller
{
    /**
     * Queue the analysis.
     */
    public function __invoke(Repository $repository): RedirectResponse
    {
        Gate::authorize('runConsulting', $repository);

        GenerateConsultingReport::queueFor($repository, force: true);

        Inertia::flash('success', __('repositories.flash.consulting_started'));

        return redirect()->route('repositories.consulting', $repository);
    }
}
