<?php

namespace App\Http\Controllers\Repository;

use App\Enums\MediaLanguage;
use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateMedia;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * „Generează video și slide-uri” and „Regenerează” on the „Video” page: a logged-in user starts the
 * media job by hand (RepositoryPolicy::generateMedia; the route shares the add limit).
 *
 * The rows are marked as waiting right here, before the job reaches a worker, so the page shows the real
 * state at once and starts refreshing itself. The job is forced: what exists is made again, with a new
 * photo of the GitHub page (the texts written by the AI are kept while the sources did not change).
 */
class GenerateMediaController extends Controller
{
    /**
     * Start the generation and go back to the page.
     */
    public function __invoke(Repository $repository): RedirectResponse
    {
        Gate::authorize('generateMedia', $repository);

        $assets = [
            ...array_map(fn (MediaLanguage $language) => $repository->videoAsset($language), MediaLanguage::configured()),
            $repository->slidesAsset(),
        ];

        foreach ($assets as $asset) {
            $asset->fill(['status' => MediaStatus::Pending, 'error' => null])->save();
        }

        GenerateMedia::dispatch($repository, force: true);

        Inertia::flash('success', __('repositories.flash.media_started'));

        return redirect()->route('repositories.media', $repository);
    }
}
