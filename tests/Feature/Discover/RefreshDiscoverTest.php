<?php

namespace Tests\Feature\Discover;

use App\Models\StarSnapshot;
use App\Models\WatchedRepository;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

/**
 * `php artisan discover:refresh`: the searches on the GitHub Search API (always Http::fake here),
 * the upsert of the watched repositories and the daily star snapshots.
 */
class RefreshDiscoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo('2026-11-11 10:00:00');

        // Two fixed searches and one language: three searches per run, unless a test changes it.
        config([
            'gitchronicle.discover.languages' => ['Rust'],
            'gitchronicle.discover.per_page' => 50,
            'gitchronicle.discover.max_pages' => 2,
            'gitchronicle.discover.refresh_limit' => 40,
            'services.github.token' => null,
        ]);
    }

    public function test_it_watches_the_repositories_found_and_writes_todays_snapshot(): void
    {
        Http::fake(['api.github.com/search/repositories*' => Http::response(['items' => [
            self::item(101, 'Acme/Rocket', 1500),
            self::item(202, 'zed/tiny', 60, ['language' => null, 'description' => '  ', 'license' => ['spdx_id' => 'NOASSERTION']]),
        ]])]);

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('Noi și populare, pagina 1: 2 repository-uri')
            ->expectsOutputToContain('Noi în Rust, pagina 1: 2 repository-uri')
            ->expectsOutputToContain('Gata: 3 cereri către GitHub, 2 repository-uri văzute azi (2 noi), 2 notări pentru 2026-11-11')
            ->assertSuccessful();

        $rocket = WatchedRepository::firstWhere('github_id', 101);
        $this->assertSame('Acme/Rocket', $rocket->full_name);
        $this->assertSame('Un motor de rachete.', $rocket->description);
        $this->assertSame('Rust', $rocket->language);
        $this->assertSame(['cli', 'space'], $rocket->topics);
        $this->assertSame('MIT', $rocket->license);
        $this->assertSame(1500, $rocket->stars);
        $this->assertSame('2026-10-20 08:00:00', $rocket->github_created_at->toDateTimeString());
        $this->assertSame('2026-11-11 10:00:00', $rocket->first_seen_at->toDateTimeString());
        $this->assertSame([['2026-11-11', 1500]], $rocket->snapshots->map(fn (StarSnapshot $snapshot) => [$snapshot->captured_on, $snapshot->stars])->all());

        $tiny = WatchedRepository::firstWhere('github_id', 202);
        $this->assertNull($tiny->language);
        $this->assertNull($tiny->description);
        $this->assertNull($tiny->license);

        // The explicit searches, sorted by stars, with a generic User-Agent and no token.
        $queries = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['q'])->all();
        $this->assertSame([
            'created:>=2026-10-12 stars:>=50',
            'pushed:>=2026-11-04 stars:>=1000',
            'language:"Rust" created:>=2026-10-12 stars:>=10',
        ], $queries);
        Http::assertSent(fn (Request $request): bool => $request->data()['sort'] === 'stars'
            && $request->data()['per_page'] === 50
            && $request->hasHeader('User-Agent', 'GitChronicle')
            && ! $request->hasHeader('Authorization'));

        // Without a token: 8 seconds between two searches (7.5 per minute, under GitHub's 10).
        Sleep::assertSequence([Sleep::for(8)->seconds(), Sleep::for(8)->seconds()]);
    }

    public function test_a_github_token_from_the_config_is_sent_and_the_searches_come_closer(): void
    {
        config(['services.github.token' => 'test-token', 'gitchronicle.discover.languages' => []]);
        Http::fake(['api.github.com/*' => Http::response(['items' => []])]);

        $this->artisan('discover:refresh')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    public function test_it_follows_the_next_pages_up_to_the_configured_maximum(): void
    {
        config(['gitchronicle.discover.languages' => []]);
        Http::fake(function (Request $request) {
            $page = (int) $request->data()['page'];
            $isFirstSearch = str_starts_with($request->data()['q'], 'created:');

            // The first search always says there is a next page; the second has a single page.
            return Http::response(
                ['items' => [self::item(($isFirstSearch ? 1000 : 2000) + $page, "acme/repo-{$page}-".($isFirstSearch ? 'a' : 'b'), 100)]],
                200,
                $isFirstSearch ? ['Link' => '<https://api.github.com/search/repositories?page='.($page + 1).'>; rel="next"'] : [],
            );
        });

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('Noi și populare, pagina 2: 1 repository-uri')
            ->assertSuccessful();

        $pages = Http::recorded()->map(fn (array $pair) => [substr($pair[0]->data()['q'], 0, 8), (int) $pair[0]->data()['page']])->all();
        $this->assertSame([['created:', 1], ['created:', 2], ['pushed:>', 1]], $pages);
        $this->assertSame(3, WatchedRepository::count());
    }

    public function test_the_rate_limit_stops_the_run_politely_and_keeps_what_was_collected(): void
    {
        Log::spy();
        Http::fakeSequence('api.github.com/search/*')
            ->push(['items' => [self::item(101, 'acme/rocket', 1500)]], 200, ['X-RateLimit-Remaining' => '8', 'X-RateLimit-Reset' => (string) now()->addMinute()->getTimestamp()])
            ->push(['message' => 'API rate limit exceeded for 1.2.3.4.'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) now()->addMinutes(10)->getTimestamp()]);

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('Oprit înainte de final: Limita de cereri a API-ului GitHub e atinsă până la 10:10:00 UTC.')
            ->assertFailed();

        // The 403 is not retried and the third search is never sent.
        Http::assertSentCount(2);
        $this->assertSame(1, StarSnapshot::where('captured_on', '2026-11-11')->count());
        Log::shouldHaveReceived('warning')->once()->with('discover:refresh stopped early.', Mockery::on(
            fn (array $context): bool => $context['rate_limited'] === true && $context['requests'] === 2 && $context['snapshots_today'] === 1,
        ));
    }

    public function test_when_nothing_remains_it_waits_for_a_near_reset_and_stops_before_a_far_one(): void
    {
        Http::fakeSequence('api.github.com/search/*')
            ->push(['items' => []], 200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) now()->addSeconds(20)->getTimestamp()])
            ->push(['items' => []], 200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) now()->addMinutes(30)->getTimestamp()]);

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('Limita de cereri a API-ului GitHub e atinsă până la 10:30:00 UTC.')
            ->assertFailed();

        // The first reset is 20 seconds away: the client waits for it (21 s) and sends the second search;
        // the second one is 30 minutes away: the third search is not sent at all.
        Http::assertSentCount(2);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 21);
    }

    public function test_a_server_error_is_retried_with_backoff(): void
    {
        config(['gitchronicle.discover.languages' => []]);
        Http::fakeSequence('api.github.com/search/*')
            ->push(['message' => 'Server Error'], 502)
            ->push(['items' => [self::item(101, 'acme/rocket', 10)]])
            ->push(['items' => []]);

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('Gata: 3 cereri către GitHub, 1 repository-uri văzute azi')
            ->assertSuccessful();

        Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(8)->seconds()]);
    }

    public function test_a_lasting_error_fails_the_command_with_a_log_line(): void
    {
        Log::spy();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], 500)]);

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('Oprit înainte de final: GitHub a răspuns cu eroarea 500: Server Error')
            ->assertFailed();

        // One request and two retries, after 1 and 3 seconds.
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(3)->seconds()]);
        Log::shouldHaveReceived('warning')->once()->with('discover:refresh stopped early.', Mockery::on(
            fn (array $context): bool => $context['rate_limited'] === false && str_contains($context['failure'], '500'),
        ));
    }

    public function test_no_network_fails_the_command(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host: api.github.com'));

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('GitHub nu răspunde (fără rețea?)')
            ->assertFailed();

        $this->assertSame(0, WatchedRepository::count());
    }

    public function test_running_twice_on_the_same_day_keeps_one_snapshot_per_repository(): void
    {
        Http::fakeSequence('api.github.com/search/*')
            ->push(['items' => [self::item(101, 'acme/rocket', 1500)]])
            ->push(['items' => []])
            ->push(['items' => []])
            ->push(['items' => [self::item(101, 'acme/rocket', 1520)]])
            ->push(['items' => []])
            ->push(['items' => []])
            ->push(['items' => [self::item(101, 'acme/rocket', 1600)]])
            ->push(['items' => []])
            ->push(['items' => []]);

        $this->artisan('discover:refresh')->assertSuccessful();
        $this->travel(5)->hours();
        $this->artisan('discover:refresh')->assertSuccessful();

        $rocket = WatchedRepository::sole();
        $this->assertSame([['2026-11-11', 1520]], $rocket->snapshots()->get()->map(fn (StarSnapshot $snapshot) => [$snapshot->captured_on, $snapshot->stars])->all());

        $this->travelTo('2026-11-12 10:00:00');
        $this->artisan('discover:refresh')->assertSuccessful();

        $this->assertSame(
            [['2026-11-11', 1520], ['2026-11-12', 1600]],
            $rocket->snapshots()->orderBy('captured_on')->get()->map(fn (StarSnapshot $snapshot) => [$snapshot->captured_on, $snapshot->stars])->all(),
        );
        $this->assertSame('2026-11-11 10:00:00', $rocket->fresh()->first_seen_at->toDateTimeString());
        $this->assertSame(1600, $rocket->fresh()->stars);
    }

    public function test_watched_repositories_no_search_returned_are_asked_one_by_one(): void
    {
        config(['gitchronicle.discover.languages' => [], 'gitchronicle.discover.refresh_limit' => 2]);
        $older = WatchedRepository::factory()->create(['github_id' => 7, 'full_name' => 'old/project', 'last_seen_at' => now()->subDays(3)]);
        $gone = WatchedRepository::factory()->create(['github_id' => 8, 'full_name' => 'gone/project', 'last_seen_at' => now()->subDays(2)]);
        $waiting = WatchedRepository::factory()->create(['github_id' => 9, 'full_name' => 'next/project', 'last_seen_at' => now()->subDay()]);
        Http::fake([
            'api.github.com/search/*' => Http::response(['items' => []]),
            // A renamed repository: GitHub answers for its id with the new name.
            'api.github.com/repos/old/project' => Http::response(self::item(7, 'old/project-renamed', 321)),
            'api.github.com/repos/gone/project' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->artisan('discover:refresh')
            ->expectsOutputToContain('gone/project nu mai există pe GitHub: nu îl mai urmărim')
            ->expectsOutputToContain('1 repository-uri urmărite au rămas fără notare azi')
            ->assertSuccessful();

        $this->assertSame('old/project-renamed', $older->fresh()->full_name);
        $this->assertSame(321, $older->snapshots()->sole()->stars);
        $this->assertModelMissing($gone);
        // The limit was 2: the third one waits for tomorrow, without a snapshot (never a copy of old stars).
        $this->assertSame(0, $waiting->snapshots()->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'next/project'));
    }

    /**
     * One repository as the GitHub API returns it, trimmed to the fields the leaderboard reads.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function item(int $id, string $fullName, int $stars, array $overrides = []): array
    {
        return [
            'id' => $id,
            'full_name' => $fullName,
            'description' => 'Un motor de rachete.',
            'language' => 'Rust',
            'topics' => ['cli', 'space'],
            'license' => ['spdx_id' => 'MIT'],
            'stargazers_count' => $stars,
            'forks_count' => 12,
            'created_at' => '2026-10-20T08:00:00Z',
            'pushed_at' => '2026-11-10T08:00:00Z',
            ...$overrides,
        ];
    }
}
