<?php

namespace App\Actions;

use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Jobs\ProcessRepository;
use App\Models\Repository;
use App\Models\User;
use App\Support\GitHubUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Adds a GitHub repository to the public catalog. It is the only place that creates
 * a repository, so it is also the only place that starts its first processing (ProcessRepository).
 */
final class AddRepository
{
    /**
     * The session key that keeps the URL a guest asked to add, until they log in or register.
     */
    public const PENDING_URL_SESSION_KEY = 'pending_repository_url';

    /**
     * The message when the user has added as many repositories as one day allows.
     */
    public const DAILY_LIMIT_MESSAGE = 'Ai adăugat deja numărul maxim de repository-uri pentru o zi. Încearcă din nou mâine.';

    /**
     * Return the repository with this URL, creating it for the user if nobody has added it yet.
     *
     * The URL must already be canonical (StoreRepositoryRequest normalizes and validates it).
     * The caller tells the two cases apart with $repository->wasRecentlyCreated.
     *
     * Only a new repository is sent to the queue for processing (clone, ...): an existing one
     * already has its own pipeline.
     *
     * One account may add at most config('gitchronicle.limits.repositories.per_user_per_day') new
     * repositories in 24 hours (administrators are exempt), because each one costs a clone and many Claude calls. The count lives
     * in the rate limiter (not in the table), so deleting a repository does not give the place back.
     *
     * @throws ValidationException on the "url" field when the user's daily limit is reached
     */
    public function handle(User $user, string $url): Repository
    {
        $existingRepository = Repository::firstWhere('url', $url);

        if ($existingRepository !== null) {
            return $existingRepository;
        }

        $dailyKey = 'add-repository:'.$user->id;

        if (! $user->is_admin && RateLimiter::tooManyAttempts($dailyKey, config('gitchronicle.limits.repositories.per_user_per_day'))) {
            throw ValidationException::withMessages(['url' => self::DAILY_LIMIT_MESSAGE]);
        }

        RateLimiter::hit($dailyKey, 24 * 60 * 60);
        $parts = GitHubUrl::parse($url);

        $repository = $user->repositories()->create([
            'url' => $url,
            'owner' => $parts['owner'],
            'name' => $parts['name'],
            'status' => RepositoryStatus::Pending,
        ]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Add);

        return $repository;
    }

    /**
     * Right after a login or a registration: add the repository the user asked for as a guest
     * and flash the message for its page. Returns null when no repository was waiting.
     *
     * The URL is pulled from the session (read and removed), so it is added only once. When the user's
     * daily limit is reached, the login still succeeds and the limit is shown as an error message.
     * The message lives here because the login and the registration show the same one.
     */
    public function completePending(Request $request): ?Repository
    {
        $url = $request->session()->pull(self::PENDING_URL_SESSION_KEY);

        if (! is_string($url)) {
            return null;
        }

        try {
            $repository = $this->handle($request->user(), $url);
        } catch (ValidationException $exception) {
            Inertia::flash('error', $exception->getMessage());

            return null;
        }

        if ($repository->wasRecentlyCreated) {
            Inertia::flash('success', __('repositories.flash.added_pending', ['repository' => "{$repository->owner}/{$repository->name}"]));
        } else {
            Inertia::flash('info', __('repositories.flash.already_added'));
        }

        return $repository;
    }
}
