<?php

namespace App\Discover;

use App\Enums\DiscoverPeriod;
use Illuminate\Support\Collection;

/**
 * The „Descoperă” ranking of one day, for one period (and maybe one language).
 */
final readonly class Ranking
{
    /**
     * @param  string|null  $day  The ranking day ("Y-m-d"): the day of the latest snapshot, null when nothing was collected.
     * @param  string|null  $baselineDay  The day the growth is measured from: $day minus the period.
     * @param  bool  $orderedByGrowth  True when at least one repository has a measured growth for the period and the
     *                                 ranking is ordered by it; false when it falls back to the average since creation.
     * @param  Collection<int, LeaderboardEntry>  $entries  The repositories, first place first.
     */
    public function __construct(
        public DiscoverPeriod $period,
        public ?string $day,
        public ?string $baselineDay,
        public bool $orderedByGrowth,
        public Collection $entries,
    ) {}
}
