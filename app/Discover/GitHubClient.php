<?php

namespace App\Discover;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * The two GitHub REST API calls of the „Descoperă” leaderboard, through Laravel's Http client:
 * a repository search (GET /search/repositories) and one repository (GET /repos/{owner}/{name}).
 *
 * GitHub's limits are respected on purpose:
 *
 * - searches are spaced out: without a token GitHub allows 10 per minute, so there are SEARCH_PAUSE_WITHOUT_TOKEN
 *   seconds between two searches (7.5 per minute); with a token (30 per minute) SEARCH_PAUSE_WITH_TOKEN;
 * - every answer carries X-RateLimit-Remaining and X-RateLimit-Reset; when nothing remains, the client waits
 *   for the reset if it is at most MAX_WAIT_SECONDS away, otherwise it stops with GitHubRateLimited;
 * - a 403/429 that says the limit is reached also stops with GitHubRateLimited (never retried);
 * - no network or a 5xx is retried twice, after 1 and then 3 seconds; after that GitHubRequestFailed.
 *
 * The only things sent are the request itself, a generic User-Agent and, when configured, the token
 * (config('services.github.token')): nothing about this app or its users.
 */
class GitHubClient
{
    public const API_URL = 'https://api.github.com';

    public const SEARCH_PAUSE_WITHOUT_TOKEN = 8;

    public const SEARCH_PAUSE_WITH_TOKEN = 3;

    public const MAX_WAIT_SECONDS = 60;

    /**
     * How many HTTP requests were sent (retries included), for the summary of the command.
     */
    private int $requestCount = 0;

    /**
     * How many searches were sent, to know when to pause before the next one.
     */
    private int $searchCount = 0;

    /**
     * What GitHub said about its limits in the last answer, per resource ("search" or "core").
     *
     * @var array<string, array{remaining: int, reset: int}>
     */
    private array $limits = [];

    /**
     * The optional token, or null to use the public limits.
     */
    private readonly ?string $token;

    /**
     * The container passes the token from config/services.php (GITHUB_TOKEN); an empty one means no token.
     */
    public function __construct(#[Config('services.github.token')] ?string $token = null)
    {
        $this->token = filled($token) ? $token : null;
    }

    /**
     * One page of a repository search.
     *
     * @return array{items: list<array<string, mixed>>, has_next_page: bool}
     */
    public function searchRepositories(string $query, int $page, int $perPage): array
    {
        if ($this->searchCount > 0) {
            Sleep::for($this->token === null ? self::SEARCH_PAUSE_WITHOUT_TOKEN : self::SEARCH_PAUSE_WITH_TOKEN)->seconds();
        }

        $this->searchCount++;

        $response = $this->get('search', '/search/repositories', [
            'q' => $query,
            'sort' => 'stars',
            'order' => 'desc',
            'per_page' => $perPage,
            'page' => $page,
        ]);

        $items = $response->json('items');

        return [
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            // GitHub lists the other pages in the Link header; without rel="next" this was the last one.
            'has_next_page' => str_contains($response->header('Link'), 'rel="next"'),
        ];
    }

    /**
     * One repository by its full name ("owner/name"), or null when it no longer exists (or became private).
     * A renamed repository is followed: GitHub answers with a redirect to its new name.
     *
     * @return array<string, mixed>|null
     */
    public function repository(string $fullName): ?array
    {
        [$owner, $name] = array_pad(explode('/', $fullName, 2), 2, '');

        $response = $this->get('core', '/repos/'.rawurlencode($owner).'/'.rawurlencode($name), [], allowNotFound: true);

        return $response->status() === 404 || ! is_array($response->json()) ? null : $response->json();
    }

    /**
     * How many HTTP requests this client sent (retries included).
     */
    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Send one GET request and return a successful answer (or a 404 when it is allowed).
     *
     * @param  array<string, mixed>  $query
     *
     * @throws GitHubRateLimited when the limit is reached
     * @throws GitHubRequestFailed when GitHub cannot answer
     */
    private function get(string $resource, string $path, array $query, bool $allowNotFound = false): Response
    {
        $this->waitForTheLimit($resource);

        try {
            $response = $this->request()->get(self::API_URL.$path, $query);
        } catch (ConnectionException $exception) {
            throw new GitHubRequestFailed('GitHub does not answer (no network?): '.$exception->getMessage(), previous: $exception);
        }

        $this->rememberTheLimit($resource, $response);

        if ($this->isRateLimited($response)) {
            throw new GitHubRateLimited($this->resetTime($response));
        }

        if ($response->successful() || ($allowNotFound && $response->status() === 404)) {
            return $response;
        }

        $message = is_string($response->json('message')) ? ': '.Str::limit($response->json('message'), 200) : '';

        throw new GitHubRequestFailed("GitHub answered with error {$response->status()}{$message}");
    }

    /**
     * The request every call starts from: JSON, the API version, a generic User-Agent (GitHub requires one),
     * the optional token, a 15-second timeout and the retries of a lost connection or a 5xx.
     */
    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'GitChronicle', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->connectTimeout(5)
            ->timeout(15)
            ->retry([1000, 3000], when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
            ->beforeSending(function (): void {
                $this->requestCount++;
            });

        return $this->token === null ? $request : $request->withToken($this->token);
    }

    /**
     * When the last answer said nothing remains of this resource's limit: wait for the reset when it is
     * near (the search limit resets every minute), otherwise stop.
     */
    private function waitForTheLimit(string $resource): void
    {
        $limit = $this->limits[$resource] ?? null;

        if ($limit === null || $limit['remaining'] > 0) {
            return;
        }

        $seconds = $limit['reset'] - now()->getTimestamp() + 1;

        if ($seconds <= 0) {
            return;
        }

        if ($seconds > self::MAX_WAIT_SECONDS) {
            throw new GitHubRateLimited($limit['reset']);
        }

        Sleep::for($seconds)->seconds();
    }

    /**
     * Keep X-RateLimit-Remaining and X-RateLimit-Reset of an answer, when GitHub sent them.
     */
    private function rememberTheLimit(string $resource, Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');
        $reset = $response->header('X-RateLimit-Reset');

        if (is_numeric($remaining) && is_numeric($reset)) {
            $this->limits[$resource] = ['remaining' => (int) $remaining, 'reset' => (int) $reset];
        }
    }

    /**
     * GitHub says the limit is reached with a 403 or a 429, together with X-RateLimit-Remaining: 0,
     * a Retry-After header (the "secondary" limit) or a message about the rate limit.
     */
    private function isRateLimited(Response $response): bool
    {
        if (! in_array($response->status(), [403, 429], true)) {
            return false;
        }

        return $response->header('X-RateLimit-Remaining') === '0'
            || $response->header('Retry-After') !== ''
            || (is_string($response->json('message')) && str_contains(Str::lower($response->json('message')), 'rate limit'));
    }

    /**
     * When GitHub allows requests again, from Retry-After (seconds) or X-RateLimit-Reset (Unix time).
     */
    private function resetTime(Response $response): ?int
    {
        if (is_numeric($response->header('Retry-After'))) {
            return now()->getTimestamp() + (int) $response->header('Retry-After');
        }

        return is_numeric($response->header('X-RateLimit-Reset')) ? (int) $response->header('X-RateLimit-Reset') : null;
    }
}
