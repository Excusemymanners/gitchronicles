<?php

namespace App\Media;

use App\Models\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What GitHub says about a repository (stars, main language, license, topics, homepage, description),
 * read from the public REST API without a token: https://api.github.com/repos/{owner}/{name}.
 *
 * Without a token GitHub allows 60 requests per hour per IP address, so an answer is kept in the cache
 * for CACHE_HOURS. Any failure (no network, rate limit, a missing repository) gives null: the video and
 * the slides then simply leave out what only GitHub knows. A failure is not cached, so the next
 * generation tries again.
 */
final class GitHubMetadata
{
    /**
     * How long an answer from GitHub is reused.
     */
    public const CACHE_HOURS = 6;

    /**
     * The facts of the repository, or null when GitHub could not be asked.
     *
     * @return array{stars: int, forks: int, language: string|null, license: string|null, topics: list<string>, homepage: string|null, description: string|null}|null
     */
    public function for(Repository $repository): ?array
    {
        $key = 'github-repository:'.strtolower("{$repository->owner}/{$repository->name}");

        return Cache::remember($key, now()->addHours(self::CACHE_HOURS), fn (): ?array => $this->fetch($repository));
    }

    /**
     * One request to the API. GitHub asks every client for a User-Agent; the app's name is enough.
     *
     * @return array{stars: int, forks: int, language: string|null, license: string|null, topics: list<string>, homepage: string|null, description: string|null}|null
     */
    private function fetch(Repository $repository): ?array
    {
        $url = 'https://api.github.com/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name);

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'GitChronicle', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::info('GitHub API not reachable.', ['repository_id' => $repository->id, 'message' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            Log::info('GitHub API answered with an error.', ['repository_id' => $repository->id, 'status' => $response->status()]);

            return null;
        }

        $data = $response->json();
        $license = $data['license']['spdx_id'] ?? null;

        return [
            'stars' => (int) ($data['stargazers_count'] ?? 0),
            'forks' => (int) ($data['forks_count'] ?? 0),
            'language' => self::text($data['language'] ?? null),
            // NOASSERTION means GitHub found a license file but could not tell which license it is.
            'license' => $license === 'NOASSERTION' ? null : self::text($license),
            'topics' => array_values(array_filter($data['topics'] ?? [], 'is_string')),
            'homepage' => self::text($data['homepage'] ?? null),
            'description' => self::text($data['description'] ?? null),
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
