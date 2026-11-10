<?php

namespace App\Actions;

use App\Enums\RepositoryStatus;
use App\Models\Repository;
use App\Models\User;
use App\Support\GitHubUrl;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Adds a GitHub repository to the public catalog. It is the only place that creates
 * a repository, so the clone job (Faza 3) will be started here, in handle().
 */
final class AddRepository
{
    /**
     * The session key that keeps the URL a guest asked to add, until they log in or register.
     */
    public const PENDING_URL_SESSION_KEY = 'pending_repository_url';

    /**
     * Return the repository with this URL, creating it for the user if nobody has added it yet.
     *
     * The URL must already be canonical (StoreRepositoryRequest normalizes and validates it).
     * The caller tells the two cases apart with $repository->wasRecentlyCreated.
     */
    public function handle(User $user, string $url): Repository
    {
        $existingRepository = Repository::firstWhere('url', $url);

        if ($existingRepository !== null) {
            return $existingRepository;
        }

        $parts = GitHubUrl::parse($url);

        return $user->repositories()->create([
            'url' => $url,
            'owner' => $parts['owner'],
            'name' => $parts['name'],
            'status' => RepositoryStatus::Pending,
        ]);
    }

    /**
     * Right after a login or a registration: add the repository the user asked for as a guest
     * and flash the message for its page. Returns null when no repository was waiting.
     *
     * The URL is pulled from the session (read and removed), so it is added only once.
     * The message lives here because the login and the registration show the same one.
     */
    public function completePending(Request $request): ?Repository
    {
        $url = $request->session()->pull(self::PENDING_URL_SESSION_KEY);

        if (! is_string($url)) {
            return null;
        }

        $repository = $this->handle($request->user(), $url);

        if ($repository->wasRecentlyCreated) {
            Inertia::flash('success', "Am adăugat {$repository->owner}/{$repository->name}.");
        } else {
            Inertia::flash('info', 'Repository-ul era deja adăugat.');
        }

        return $repository;
    }
}
