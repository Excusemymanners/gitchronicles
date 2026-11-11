<?php

namespace App\Discover;

use App\Models\WatchedRepository;

/**
 * One repository in the „Descoperă” ranking, with the numbers of the ranking day.
 */
final readonly class LeaderboardEntry
{
    /**
     * @param  int  $stars  The stars of the ranking day's snapshot.
     * @param  int|null  $growth  Stars gained during the period (ranking day minus the day exactly one period before),
     *                            or null when one of the two snapshots is missing: unknown, never 0.
     * @param  int  $ageDays  Whole days from the creation on GitHub to the ranking day.
     * @param  float  $averagePerDay  Stars per day since the creation: stars ÷ age in days (at least one day).
     *                                Real from the first snapshot, but an average over the whole life, not a recent growth.
     */
    public function __construct(
        public WatchedRepository $repository,
        public int $stars,
        public ?int $growth,
        public int $ageDays,
        public float $averagePerDay,
    ) {}
}
