<?php

namespace App\Http\Controllers;

use App\Actions\AddRepository;
use App\Actions\ReprocessRepository;
use App\Actions\UpdateRepository;
use App\Enums\DiagramType;
use App\Enums\ProcessingTrigger;
use App\Git\HistoryStatistics;
use App\Http\Controllers\Repository\RendersRepositoryPage;
use App\Http\Requests\StoreRepositoryRequest;
use App\Http\Resources\CodeStructureResource;
use App\Http\Resources\DiagramResource;
use App\Http\Resources\OverviewSummaryResource;
use App\Http\Resources\RepositoryCardResource;
use App\Models\Document;
use App\Models\Repository;
use App\Support\GitHubUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public catalog of repositories: anyone may browse it, only logged-in users add to it.
 */
class RepositoryController extends Controller
{
    use RendersRepositoryPage;

    /**
     * The home page: every repository, newest first, each as a gallery card with real facts about it
     * (files, modules, commits, languages, which chapters exist). The facts are aggregates loaded for
     * the whole list at once (RepositoryCardResource), never one query per repository.
     */
    public function index(): Response
    {
        $repositories = RepositoryCardResource::withFacts(Repository::query())
            ->latest()
            ->latest('id')
            ->get();

        return Inertia::render('home', [
            'mesaj' => 'Documentație vie pentru repository-urile tale',
            'repositories' => RepositoryCardResource::cards($repositories),
        ]);
    }

    /**
     * Add a GitHub repository. Guests may submit the form too:
     *
     * 1. the link is normalized and validated (StoreRepositoryRequest);
     * 2. a repository that already exists just opens its page;
     * 3. a guest is sent to log in, and the URL waits in the session (see AddRepository::completePending);
     * 4. a logged-in user gets the repository created and opened.
     *
     * The URL is canonical here, so GitHubUrl::parse() cannot fail.
     */
    public function store(StoreRepositoryRequest $request, AddRepository $addRepository): RedirectResponse
    {
        $url = $request->validated('url');

        $existingRepository = Repository::firstWhere('url', $url);

        if ($existingRepository !== null) {
            Inertia::flash('info', 'Repository-ul era deja adăugat.');

            return redirect()->route('repositories.show', $existingRepository);
        }

        if ($request->user() === null) {
            $request->session()->put(AddRepository::PENDING_URL_SESSION_KEY, $url);

            $parts = GitHubUrl::parse($url);
            Inertia::flash('info', "Intră în cont ca să adăugăm {$parts['owner']}/{$parts['name']}.");

            return redirect()->route('login');
        }

        $repository = $addRepository->handle($request->user(), $url);

        Inertia::flash('success', 'Repository adăugat.');

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * The „Prezentare” tab, open to anyone: the header of every repository page (RendersRepositoryPage)
     * plus one prop for each section of the page. A section's prop stays null until its phase
     * fills it from the repository's real data, and a null section is not shown at all.
     *
     * "code_structure" is the result of the code analysis, or null until the first analysis ends.
     * It is a closure, so Inertia runs its queries only when the page asks for this prop.
     *
     * While the repository is processed, the page asks again every 2 seconds for
     * "repository" and "can_retry" only (Inertia polling with a partial reload, in RepositoryLayout).
     */
    public function show(Request $request, Repository $repository, HistoryStatistics $statistics): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/show', [
            // „Despre proiect”: the first sections of the generated overview page (Faza 6), null until it is written.
            'overview' => function () use ($repository): ?array {
                $overview = Document::whereBelongsTo($repository)->firstWhere('slug', Document::OVERVIEW);

                return $overview === null ? null : OverviewSummaryResource::make($overview)->resolve();
            },

            // „Activitate”: the commits of the last 12 months and the contributors (Istoric), null until the first import.
            'activity' => fn () => $statistics->activity($repository),

            // „Zone fierbinți”: the 5 files changed most often (Istoric), null when there are none.
            'hotspots' => fn () => $statistics->hotspots($repository, 5) ?: null,

            // A preview of the architecture diagram (Faza 7): the layers view, null until BuildDiagrams draws it.
            'diagram_preview' => function () use ($repository): ?array {
                $layers = $repository->diagrams()->firstWhere('type', DiagramType::Layers);

                return $layers === null ? null : DiagramResource::make($layers)->resolve();
            },

            // „Structura codului”: the result of the code analysis (Faza 5), null until the first analysis ends.
            'code_structure' => fn () => $repository->analyzed_at === null
                ? null
                : CodeStructureResource::make($repository)->resolve(),
        ]);
    }

    /**
     * Start the processing again after a failure (RepositoryPolicy::retry) and stay on its page,
     * which then follows the new run.
     */
    public function retry(Repository $repository, ReprocessRepository $reprocessRepository): RedirectResponse
    {
        Gate::authorize('retry', $repository);

        $reprocessRepository->handle($repository);

        Inertia::flash('success', 'Am pornit din nou procesarea.');

        return redirect()->route('repositories.show', $repository);
    }

    /**
     * „Verifică actualizări” (RepositoryPolicy::checkUpdates): start an update and go back to the page
     * the button was pressed on, which then follows the run. When GitHub has no new commit the run
     * ends in a moment as „nimic nou”.
     */
    public function checkUpdates(Repository $repository, UpdateRepository $updateRepository): RedirectResponse
    {
        Gate::authorize('checkUpdates', $repository);

        $updateRepository->handle($repository, ProcessingTrigger::Manual);

        Inertia::flash('success', 'Verificăm dacă sunt commit-uri noi pe GitHub.');

        return redirect()->back(fallback: route('repositories.show', $repository));
    }

    /**
     * Delete a repository: only the user who added it may do this (RepositoryPolicy).
     * Its clone folder is deleted with it (see Repository::booted()).
     */
    public function destroy(Repository $repository): RedirectResponse
    {
        Gate::authorize('delete', $repository);

        $repository->delete();

        Inertia::flash('success', 'Repository șters.');

        return redirect()->route('home');
    }
}
