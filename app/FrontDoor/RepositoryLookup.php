<?php

namespace App\FrontDoor;

use App\Discover\GitHubClient;
use App\Discover\GitHubRequestFailed;
use Illuminate\Support\Facades\Cache;

/**
 * What GitHub says about a repository nobody added yet, for the „Document this repository” page.
 *
 * It reuses the Discover GitHubClient (one GitHub client in the app: the same User-Agent, the same
 * optional token, the same rate-limit handling). The answer is kept for CACHE_MINUTES, "not found" too, so
 * a link shared in a chat does not cost one API call per click. When GitHub cannot be asked the state is
 * "unavailable" and no facts are shown: nothing is invented, and the failure is remembered only for a
 * moment (UNAVAILABLE_SECONDS) so a rate limit is not hammered.
 *
 * A private repository counts as "missing": unauthenticated GitHub answers 404 for it, and with a token
 * configured we must not reveal what the token can see.
 */
final class RepositoryLookup
{
    public const FOUND = 'found';

    public const MISSING = 'missing';

    public const UNAVAILABLE = 'unavailable';

    public const CACHE_MINUTES = 10;

    public const UNAVAILABLE_SECONDS = 60;

    public function __construct(private readonly GitHubClient $github) {}

    /**
     * The state of the repository on GitHub and, when it is found, its facts.
     *
     * @return array{state: string, facts: array{full_name: string, description: string|null, avatar_url: string|null, stars: int, forks: int, language: string|null, license: string|null, pushed_at: string|null, default_branch: string|null}|null}
     */
    public function find(string $owner, string $name): array
    {
        $key = 'frontdoor:'.strtolower("{$owner}/{$name}");

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $answer = $this->ask($owner, $name);

        Cache::put(
            $key,
            $answer,
            $answer['state'] === self::UNAVAILABLE ? now()->addSeconds(self::UNAVAILABLE_SECONDS) : now()->addMinutes(self::CACHE_MINUTES),
        );

        return $answer;
    }

    /**
     * One call to GitHub, turned into a state and the facts the page shows.
     *
     * @return array{state: string, facts: array<string, mixed>|null}
     */
    private function ask(string $owner, string $name): array
    {
        try {
            $data = $this->github->repository("{$owner}/{$name}");
        } catch (GitHubRequestFailed) {
            return ['state' => self::UNAVAILABLE, 'facts' => null];
        }

        if ($data === null || ($data['private'] ?? false) === true || ! is_string($data['full_name'] ?? null)) {
            return ['state' => self::MISSING, 'facts' => null];
        }

        $license = $data['license']['spdx_id'] ?? null;
        $avatar = $data['owner']['avatar_url'] ?? null;

        return [
            'state' => self::FOUND,
            'facts' => [
                'full_name' => $data['full_name'],
                'description' => self::text($data['description'] ?? null),
                'avatar_url' => is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
                'stars' => (int) ($data['stargazers_count'] ?? 0),
                'forks' => (int) ($data['forks_count'] ?? 0),
                'language' => self::text($data['language'] ?? null),
                // NOASSERTION means GitHub found a license file but could not tell which license it is.
                'license' => $license === 'NOASSERTION' ? null : self::text($license),
                'pushed_at' => self::text($data['pushed_at'] ?? null),
                'default_branch' => self::text($data['default_branch'] ?? null),
            ],
        ];
    }

    /**
     * A non-empty string from the JSON, or null.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
