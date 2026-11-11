<?php

namespace App\Discover;

/**
 * What one run of `discover:refresh` did, for the terminal and the log.
 */
final readonly class CollectionSummary
{
    /**
     * @param  string  $day  The day of the snapshots ("Y-m-d").
     * @param  int  $requests  HTTP requests sent to GitHub (retries included).
     * @param  int  $repositoriesSeen  Different repositories GitHub returned today.
     * @param  int  $newRepositories  Of those, the ones watched for the first time.
     * @param  int  $snapshotsToday  Repositories with a snapshot for the day, after the run.
     * @param  int  $notRefreshed  Watched repositories left without a snapshot for the day (limits).
     * @param  int  $removed  Watched repositories that no longer exist on GitHub, removed.
     * @param  string|null  $failure  Why the run stopped early, or null when it finished.
     * @param  bool  $rateLimited  True when it stopped because of GitHub's rate limit.
     */
    public function __construct(
        public string $day,
        public int $requests,
        public int $repositoriesSeen,
        public int $newRepositories,
        public int $snapshotsToday,
        public int $notRefreshed,
        public int $removed,
        public ?string $failure = null,
        public bool $rateLimited = false,
    ) {}

    /**
     * True when GitHub stopped the run before everything was asked.
     */
    public function failed(): bool
    {
        return $this->failure !== null;
    }

    /**
     * The numbers, for the log line.
     *
     * @return array<string, int|string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'day' => $this->day,
            'requests' => $this->requests,
            'repositories_seen' => $this->repositoriesSeen,
            'new_repositories' => $this->newRepositories,
            'snapshots_today' => $this->snapshotsToday,
            'not_refreshed' => $this->notRefreshed,
            'removed' => $this->removed,
            'failure' => $this->failure,
            'rate_limited' => $this->rateLimited,
        ];
    }
}
