<?php

namespace App\Discover;

use App\Enums\DiscoverPeriod;
use App\Models\StarSnapshot;
use App\Models\WatchedRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The „Descoperă” ranking, computed only from real snapshots (StarSnapshot).
 *
 * - The ranking day is the day of the latest snapshot; only the repositories with a snapshot that day take part,
 *   so every number is that day's number.
 * - Growth for a period = stars on the ranking day − stars on the day exactly one period before (1, 7 or 30 days).
 *   Without both snapshots the growth is unknown (null): never 0 and never estimated.
 * - Average since creation = stars ÷ age in days. It is real from the very first snapshot, but it is an average
 *   over the whole life of the repository, and the page says so.
 * - Order: by measured growth when at least one repository has it for the period (unknown growth after the
 *   known ones), otherwise by the average since creation. Ties: more stars first, then the older entry.
 *
 * The data is small (one row per watched repository), so the numbers are computed and sorted in PHP:
 * simple to read and the same on SQLite and Postgres.
 */
class Leaderboard
{
    /**
     * The ranking day ("Y-m-d"), or null when `discover:refresh` never ran.
     */
    public function day(): ?string
    {
        return StarSnapshot::max('captured_on');
    }

    /**
     * The day of the first snapshot ever, to say on the page since when the stars are written down.
     */
    public function firstDay(): ?string
    {
        return StarSnapshot::min('captured_on');
    }

    /**
     * The ranking for a period, optionally only one language (as GitHub names it, for example "Rust").
     */
    public function rank(DiscoverPeriod $period, ?string $language = null): Ranking
    {
        $day = $this->day();

        if ($day === null) {
            return new Ranking($period, null, null, false, collect());
        }

        $rankingDay = CarbonImmutable::parse($day);
        $baselineDay = $rankingDay->subDays($period->days())->toDateString();

        $repositories = $this->repositoriesOf($day)
            ->when($language !== null, fn (Builder $query) => $query->where('language', $language))
            ->with(['snapshots' => fn ($query) => $query->whereIn('captured_on', [$day, $baselineDay])])
            ->get();

        $entries = $repositories->map(function (WatchedRepository $repository) use ($day, $baselineDay, $rankingDay): LeaderboardEntry {
            $stars = $repository->snapshots->firstWhere('captured_on', $day)->stars;
            $baseline = $repository->snapshots->firstWhere('captured_on', $baselineDay);
            $ageDays = max(0, (int) CarbonImmutable::parse($repository->github_created_at->toDateString())->diffInDays($rankingDay));

            return new LeaderboardEntry(
                repository: $repository,
                stars: $stars,
                growth: $baseline === null ? null : $stars - $baseline->stars,
                ageDays: $ageDays,
                averagePerDay: $stars / max(1, $ageDays),
            );
        });

        $orderedByGrowth = $entries->contains(fn (LeaderboardEntry $entry): bool => $entry->growth !== null);

        $sorted = $entries->sort(function (LeaderboardEntry $a, LeaderboardEntry $b) use ($orderedByGrowth): int {
            if ($orderedByGrowth) {
                // A known growth comes before an unknown one, then the larger growth first.
                $byGrowth = [$b->growth !== null, $b->growth ?? 0] <=> [$a->growth !== null, $a->growth ?? 0];

                if ($byGrowth !== 0) {
                    return $byGrowth;
                }
            }

            return [$b->averagePerDay, $b->stars] <=> [$a->averagePerDay, $a->stars]
                ?: $a->repository->id <=> $b->repository->id;
        });

        return new Ranking($period, $day, $baselineDay, $orderedByGrowth, $sorted->values());
    }

    /**
     * „Repo-ul zilei”: the first place of today's default ranking (DiscoverPeriod::default(), every language),
     * or null when nothing was collected. The daily Instagram post (a later bonus) will read it from here.
     */
    public function repositoryOfTheDay(): ?LeaderboardEntry
    {
        return $this->rank(DiscoverPeriod::default())->entries->first();
    }

    /**
     * The languages of the ranking day, the most frequent first, for the language filter.
     *
     * @return list<array{name: string, count: int}>
     */
    public function languages(): array
    {
        $day = $this->day();

        if ($day === null) {
            return [];
        }

        return $this->repositoriesOf($day)
            ->whereNotNull('language')
            ->select('language')
            ->selectRaw('count(*) as repository_count')
            ->groupBy('language')
            ->orderByDesc('repository_count')
            ->orderBy('language')
            ->get()
            ->map(fn (WatchedRepository $row): array => ['name' => $row->language, 'count' => (int) $row->getAttribute('repository_count')])
            ->all();
    }

    /**
     * The watched repositories that have a snapshot on this day.
     *
     * @return Builder<WatchedRepository>
     */
    private function repositoriesOf(string $day): Builder
    {
        return WatchedRepository::query()->whereHas('snapshots', fn (Builder $query) => $query->where('captured_on', $day));
    }
}
