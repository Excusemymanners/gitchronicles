<?php

namespace Tests\Feature\Discover;

use App\Discover\Leaderboard;
use App\Discover\LeaderboardEntry;
use App\Enums\DiscoverPeriod;
use App\Models\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The „Descoperă” ranking: growth only from two real snapshots, the average since creation, the order and its fallback.
 */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-11 10:00:00');
    }

    public function test_nothing_collected_gives_an_empty_ranking(): void
    {
        $ranking = app(Leaderboard::class)->rank(DiscoverPeriod::Week);

        $this->assertNull($ranking->day);
        $this->assertTrue($ranking->entries->isEmpty());
        $this->assertFalse($ranking->orderedByGrowth);
        $this->assertNull(app(Leaderboard::class)->repositoryOfTheDay());
        $this->assertSame([], app(Leaderboard::class)->languages());
    }

    public function test_with_a_single_snapshot_the_growth_is_unknown_and_the_average_since_creation_is_known(): void
    {
        $this->watch('acme/rocket', ['2026-11-11' => 600], createdOn: '2026-10-12');

        foreach (DiscoverPeriod::cases() as $period) {
            $entry = app(Leaderboard::class)->rank($period)->entries->sole();

            $this->assertNull($entry->growth, "growth for {$period->value}");
            $this->assertSame(600, $entry->stars);
            $this->assertSame(30, $entry->ageDays);
            $this->assertSame(20.0, $entry->averagePerDay);
        }
    }

    public function test_the_growth_compares_the_ranking_day_with_the_day_exactly_one_period_before(): void
    {
        $this->watch('acme/rocket', [
            '2026-10-12' => 100,
            '2026-11-04' => 400,
            '2026-11-05' => 410,
            '2026-11-10' => 480,
            '2026-11-11' => 500,
        ]);

        $growth = fn (DiscoverPeriod $period): ?int => app(Leaderboard::class)->rank($period)->entries->sole()->growth;

        $this->assertSame(20, $growth(DiscoverPeriod::Day));
        $this->assertSame(100, $growth(DiscoverPeriod::Week));
        $this->assertSame(400, $growth(DiscoverPeriod::Month));
    }

    public function test_two_snapshots_measure_only_the_period_they_span_and_no_change_is_a_real_zero(): void
    {
        $this->watch('acme/rocket', ['2026-11-10' => 500, '2026-11-11' => 500]);

        $this->assertSame(0, app(Leaderboard::class)->rank(DiscoverPeriod::Day)->entries->sole()->growth);
        $this->assertNull(app(Leaderboard::class)->rank(DiscoverPeriod::Week)->entries->sole()->growth);
    }

    public function test_the_order_uses_the_measured_growth_and_puts_an_unknown_growth_last(): void
    {
        $this->watch('slow/grower', ['2026-11-04' => 1000, '2026-11-11' => 1050]);
        $this->watch('fast/grower', ['2026-11-04' => 100, '2026-11-11' => 300]);
        // New today: a huge average since creation, but its growth over 7 days is not measured yet.
        $this->watch('brand/new', ['2026-11-11' => 5000], createdOn: '2026-11-09');

        $ranking = app(Leaderboard::class)->rank(DiscoverPeriod::Week);

        $this->assertTrue($ranking->orderedByGrowth);
        $this->assertSame('2026-11-04', $ranking->baselineDay);
        $this->assertSame(['fast/grower', 'slow/grower', 'brand/new'], $this->names($ranking->entries->all()));
        $this->assertSame([200, 50, null], $ranking->entries->map(fn (LeaderboardEntry $entry) => $entry->growth)->all());
    }

    public function test_without_a_measured_growth_the_order_falls_back_to_the_average_since_creation(): void
    {
        $this->watch('old/giant', ['2026-11-11' => 1000], createdOn: '2026-08-03');
        $this->watch('young/star', ['2026-11-11' => 300], createdOn: '2026-11-01');
        $this->watch('young/twin', ['2026-11-11' => 300], createdOn: '2026-11-01');

        $ranking = app(Leaderboard::class)->rank(DiscoverPeriod::Week);

        $this->assertFalse($ranking->orderedByGrowth);
        // 300 ÷ 10 days = 30 per day before 1000 ÷ 100 days = 10 per day; a tie keeps the older entry first.
        $this->assertSame(['young/star', 'young/twin', 'old/giant'], $this->names($ranking->entries->all()));
        $this->assertSame([30.0, 30.0, 10.0], $ranking->entries->map(fn (LeaderboardEntry $entry) => $entry->averagePerDay)->all());
    }

    public function test_only_the_repositories_seen_on_the_ranking_day_take_part(): void
    {
        $this->watch('seen/today', ['2026-11-10' => 10, '2026-11-11' => 20]);
        $this->watch('seen/yesterday', ['2026-11-10' => 9000]);

        $ranking = app(Leaderboard::class)->rank(DiscoverPeriod::Day);

        $this->assertSame('2026-11-11', $ranking->day);
        $this->assertSame(['seen/today'], $this->names($ranking->entries->all()));
    }

    public function test_the_language_filter_and_the_languages_of_the_day(): void
    {
        $this->watch('a/rust-one', ['2026-11-11' => 10], language: 'Rust');
        $this->watch('b/rust-two', ['2026-11-11' => 20], language: 'Rust');
        $this->watch('c/php-one', ['2026-11-11' => 30], language: 'PHP');
        $this->watch('d/no-language', ['2026-11-11' => 40], language: null);

        $this->assertSame(['b/rust-two', 'a/rust-one'], $this->names(app(Leaderboard::class)->rank(DiscoverPeriod::Week, 'Rust')->entries->all()));
        $this->assertSame([['name' => 'Rust', 'count' => 2], ['name' => 'PHP', 'count' => 1]], app(Leaderboard::class)->languages());
    }

    public function test_the_repository_of_the_day_is_the_first_place_of_the_default_ranking(): void
    {
        $this->watch('weekly/winner', ['2026-11-04' => 100, '2026-11-11' => 900]);
        $this->watch('daily/winner', ['2026-11-04' => 100, '2026-11-10' => 100, '2026-11-11' => 600]);

        $this->assertSame(DiscoverPeriod::Week, DiscoverPeriod::default());
        $this->assertSame('weekly/winner', app(Leaderboard::class)->repositoryOfTheDay()->repository->full_name);
    }

    /**
     * A watched repository with one snapshot per given day.
     *
     * @param  array<string, int>  $starsByDay
     */
    private function watch(string $fullName, array $starsByDay, string $createdOn = '2026-01-01', ?string $language = 'Rust'): WatchedRepository
    {
        $repository = WatchedRepository::factory()->create([
            'full_name' => $fullName,
            'language' => $language,
            'github_created_at' => "{$createdOn} 12:00:00",
        ]);

        foreach ($starsByDay as $day => $stars) {
            $repository->snapshots()->create(['captured_on' => $day, 'stars' => $stars, 'forks' => 0]);
        }

        return $repository;
    }

    /**
     * @param  list<LeaderboardEntry>  $entries
     * @return list<string>
     */
    private function names(array $entries): array
    {
        return array_map(fn (LeaderboardEntry $entry): string => $entry->repository->full_name, $entries);
    }
}
