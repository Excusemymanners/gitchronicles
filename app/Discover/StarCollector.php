<?php

namespace App\Discover;

use App\Models\StarSnapshot;
use App\Models\WatchedRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The daily work of `discover:refresh`: find interesting repositories with the GitHub Search API and write
 * down today's stars of every watched repository (one StarSnapshot per repository and day).
 *
 * 1. A few explicit searches (see searches()), each on at most max_pages pages: every repository they return
 *    is upserted by its GitHub id and gets today's snapshot, with the stars GitHub just reported.
 * 2. The watched repositories that no search returned today (they are older than a month, for example)
 *    are asked for one by one, the longest unseen first, at most refresh_limit of them; a repository that
 *    no longer exists is not watched any more.
 *
 * A snapshot is only ever written with the stars GitHub reported that day: a repository GitHub was not
 * asked about today gets no snapshot (never a copy of an older number). Running twice on the same day
 * updates the same snapshot. When GitHub stops the run (rate limit, error), what was collected is kept.
 */
class StarCollector
{
    public function __construct(private readonly GitHubClient $github) {}

    /**
     * The searches of one day, in the order they are sent. Each one is a GitHub search query sorted by stars:
     *
     * - new and already popular: created in the last 30 days, at least 50 stars;
     * - active and big: pushed to in the last 7 days, at least 1000 stars;
     * - new in each popular language of config('gitchronicle.discover.languages'): created in the last
     *   30 days, at least 10 stars, so a smaller language is not hidden by the big ones.
     *
     * @return list<array{label: string, query: string}>
     */
    public function searches(CarbonImmutable $today): array
    {
        $monthAgo = $today->subDays(30)->toDateString();
        $weekAgo = $today->subDays(7)->toDateString();

        $searches = [
            ['label' => 'New and popular', 'query' => "created:>={$monthAgo} stars:>=50"],
            ['label' => 'Active, with many stars', 'query' => "pushed:>={$weekAgo} stars:>=1000"],
        ];

        foreach (config('gitchronicle.discover.languages') as $language) {
            $searches[] = ['label' => "New in {$language}", 'query' => "language:\"{$language}\" created:>={$monthAgo} stars:>=10"];
        }

        return $searches;
    }

    /**
     * Run the searches and the refresh, and write today's snapshots.
     *
     * @param  (callable(string): void)|null  $report  Receives one Romanian line after each step, for the terminal.
     */
    public function collect(?callable $report = null): CollectionSummary
    {
        $report ??= fn (string $line) => null;
        $today = CarbonImmutable::now();
        $day = $today->toDateString();
        $perPage = (int) config('gitchronicle.discover.per_page');
        $maxPages = (int) config('gitchronicle.discover.max_pages');

        /** @var array<int, true> $seen GitHub ids of the repositories returned today. */
        $seen = [];
        $new = 0;
        $removed = 0;
        $failure = null;

        try {
            foreach ($this->searches($today) as $search) {
                for ($page = 1; $page <= $maxPages; $page++) {
                    $result = $this->github->searchRepositories($search['query'], $page, $perPage);

                    foreach ($result['items'] as $item) {
                        $this->store($item, $day, $seen, $new);
                    }

                    $report("{$search['label']}, page {$page}: ".count($result['items']).' '.Str::plural('repository', count($result['items'])));

                    if (! $result['has_next_page']) {
                        break;
                    }
                }
            }

            foreach ($this->repositoriesWithoutSnapshot($day)->limit((int) config('gitchronicle.discover.refresh_limit'))->get() as $repository) {
                $item = $this->github->repository($repository->full_name);

                if ($item === null) {
                    $report("{$repository->full_name} no longer exists on GitHub: no longer tracked");
                    $repository->delete();
                    $removed++;

                    continue;
                }

                $this->store($item, $day, $seen, $new);
            }
        } catch (GitHubRequestFailed $exception) {
            $failure = $exception;
        }

        return new CollectionSummary(
            day: $day,
            requests: $this->github->requestCount(),
            repositoriesSeen: count($seen),
            newRepositories: $new,
            snapshotsToday: StarSnapshot::where('captured_on', $day)->count(),
            notRefreshed: $this->repositoriesWithoutSnapshot($day)->count(),
            removed: $removed,
            failure: $failure?->getMessage(),
            rateLimited: $failure instanceof GitHubRateLimited,
        );
    }

    /**
     * Upsert one repository from GitHub's JSON (by its GitHub id) and write today's snapshot with its stars.
     * An item without an id, a full name or a creation date is skipped.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, true>  $seen
     */
    private function store(array $item, string $day, array &$seen, int &$new): void
    {
        $attributes = self::attributes($item);

        if ($attributes === null) {
            return;
        }

        $repository = WatchedRepository::firstOrNew(['github_id' => $attributes['github_id']]);
        $repository->fill($attributes);
        $repository->first_seen_at ??= now();
        $repository->last_seen_at = now();
        $repository->save();

        if ($repository->wasRecentlyCreated) {
            $new++;
        }

        $repository->snapshots()->updateOrCreate(
            ['captured_on' => $day],
            ['stars' => $attributes['stars'], 'forks' => $attributes['forks']],
        );

        $seen[$attributes['github_id']] = true;
    }

    /**
     * The columns of a watched repository from one repository of GitHub's JSON, or null when it lacks
     * what the leaderboard needs. Empty texts become null; NOASSERTION means GitHub could not tell the license.
     *
     * @param  array<string, mixed>  $item
     * @return array{github_id: int, full_name: string, description: string|null, language: string|null, topics: list<string>, license: string|null, stars: int, forks: int, github_created_at: Carbon, github_pushed_at: Carbon|null}|null
     */
    public static function attributes(array $item): ?array
    {
        if (! is_int($item['id'] ?? null) || ! is_string($item['full_name'] ?? null) || ! is_string($item['created_at'] ?? null)) {
            return null;
        }

        $license = $item['license']['spdx_id'] ?? null;

        return [
            'github_id' => $item['id'],
            'full_name' => $item['full_name'],
            'description' => self::text($item['description'] ?? null),
            'language' => self::text($item['language'] ?? null),
            'topics' => array_values(array_filter($item['topics'] ?? [], 'is_string')),
            'license' => $license === 'NOASSERTION' ? null : self::text($license),
            'stars' => max(0, (int) ($item['stargazers_count'] ?? 0)),
            'forks' => max(0, (int) ($item['forks_count'] ?? 0)),
            'github_created_at' => Carbon::parse($item['created_at']),
            'github_pushed_at' => is_string($item['pushed_at'] ?? null) ? Carbon::parse($item['pushed_at']) : null,
        ];
    }

    /**
     * The watched repositories without a snapshot for this day, the longest unseen first.
     *
     * @return Builder<WatchedRepository>
     */
    private function repositoriesWithoutSnapshot(string $day): Builder
    {
        return WatchedRepository::query()
            ->whereDoesntHave('snapshots', fn ($query) => $query->where('captured_on', $day))
            ->orderBy('last_seen_at')
            ->orderBy('id');
    }

    /**
     * A non-empty, trimmed string from the JSON, or null.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
