<?php

namespace App\Http\Controllers;

use App\Actions\AddRepository;
use App\Actions\ReprocessRepository;
use App\Http\Requests\StoreRepositoryRequest;
use App\Http\Resources\CodeStructureResource;
use App\Http\Resources\RepositoryResource;
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
    /**
     * The home page: every repository, newest first.
     */
    public function index(): Response
    {
        $repositories = Repository::query()
            ->latest()
            ->latest('id')
            ->get();

        return Inertia::render('home', [
            'mesaj' => 'Documentație vie pentru repository-urile tale',
            'repositories' => RepositoryResource::collection($repositories)->resolve(),
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
     * Show one repository to anyone. Only the user who added it sees the delete button;
     * any logged-in user sees „Reîncearcă” when the processing failed.
     *
     * "code_structure" is the result of the code analysis, or null until the first analysis ends.
     * It is a closure, so Inertia runs its queries only when the page asks for this prop.
     *
     * While the repository is processed, the page asks again every 2 seconds for
     * "repository", "can_retry" and "code_structure" only (Inertia polling with a partial reload).
     */
    public function show(Request $request, Repository $repository): Response
    {
        return Inertia::render('repositories/show', [
            'repository' => RepositoryResource::make($repository)->resolve(),
            'can_delete' => $request->user()?->can('delete', $repository) ?? false,
            'can_retry' => $request->user()?->can('retry', $repository) ?? false,
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
