<?php

namespace Tests\Feature\Discover;

use App\Actions\AddRepository;
use App\Enums\ProcessingTrigger;
use App\Jobs\ProcessRepository;
use App\Models\Repository;
use App\Models\User;
use App\Models\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The public „Descoperă” page: the ranking, its filters in the query string and the „Documentează” action.
 */
class DiscoverPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-11 10:00:00');
    }

    public function test_a_guest_sees_the_empty_state_before_anything_was_collected(): void
    {
        $this->get(route('discover'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('discover')
                ->where('filters', ['period' => '7d', 'language' => null])
                ->where('periods', [
                    ['value' => '24h', 'label' => '24 de ore'],
                    ['value' => '7d', 'label' => '7 zile'],
                    ['value' => '30d', 'label' => '30 de zile'],
                ])
                ->where('ranking.day', null)
                ->where('ranking.total', 0)
                ->where('entries', [])
                ->where('languages', [])
                ->where('repository_of_the_day', null)
            );
    }

    public function test_a_guest_sees_the_ranking_with_the_repository_of_the_day(): void
    {
        $rocket = $this->watch('Acme/Rocket', ['2026-11-04' => 1000, '2026-11-11' => 1300], language: 'Rust');
        $this->watch('zed/tiny', ['2026-11-11' => 40]);

        $this->get('/discover')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('discover')
                ->where('ranking', [
                    'day' => '2026-11-11',
                    'baseline_day' => '2026-11-04',
                    'first_day' => '2026-11-04',
                    'ordered_by' => 'growth',
                    'total' => 2,
                ])
                ->has('entries', 2)
                ->where('entries.0', [
                    'position' => 1,
                    'id' => $rocket->id,
                    'full_name' => 'Acme/Rocket',
                    'github_url' => 'https://github.com/Acme/Rocket',
                    'description' => 'Un motor de rachete.',
                    'language' => 'Rust',
                    'stars' => 1300,
                    'growth' => 300,
                    'average_per_day' => 3.1,
                    'age_days' => 419,
                    'add_url' => 'https://github.com/acme/rocket',
                    'documentation_url' => null,
                ])
                // Measured once only: the growth of zed/tiny is unknown (null), never 0.
                ->where('entries.1.full_name', 'zed/tiny')
                ->where('entries.1.growth', null)
                ->where('repository_of_the_day.full_name', 'Acme/Rocket')
                ->where('pagination', ['current_page' => 1, 'last_page' => 1, 'previous_url' => null, 'next_url' => null])
            );
    }

    public function test_the_page_says_when_it_falls_back_to_the_average_since_creation(): void
    {
        $this->watch('acme/rocket', ['2026-11-11' => 1300]);

        $this->get('/discover?period=30d')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', '30d')
                ->where('ranking.ordered_by', 'average')
                ->where('ranking.baseline_day', '2026-10-12')
                ->where('entries.0.growth', null)
            );
    }

    public function test_the_filters_come_from_the_query_string_and_unknown_values_fall_back(): void
    {
        $this->watch('a/rust-one', ['2026-11-10' => 10, '2026-11-11' => 50], language: 'Rust');
        $this->watch('b/php-one', ['2026-11-10' => 10, '2026-11-11' => 90], language: 'PHP');

        $this->get('/discover?period=24h&language=Rust')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['period' => '24h', 'language' => 'Rust'])
                ->where('ranking.ordered_by', 'growth')
                ->has('entries', 1)
                ->where('entries.0.full_name', 'a/rust-one')
                ->where('entries.0.growth', 40)
                ->where('languages', [['name' => 'PHP', 'count' => 1], ['name' => 'Rust', 'count' => 1]])
                // The repository of the day does not depend on the filters.
                ->where('repository_of_the_day.full_name', 'b/php-one')
            );

        $this->get('/discover?period=forever&page=-3')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['period' => '7d', 'language' => null])
                ->where('pagination.current_page', 1)
                ->has('entries', 2)
            );
    }

    public function test_the_ranking_is_paginated_and_the_links_keep_the_filters(): void
    {
        foreach (range(1, 30) as $number) {
            $this->watch("acme/repo-{$number}", ['2026-11-11' => 1000 - $number], language: 'Go');
        }

        $this->get('/discover?language=Go')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 25)
                ->where('pagination.last_page', 2)
                ->where('pagination.next_url', '/discover?period=7d&language=Go&page=2')
            );

        $this->get('/discover?language=Go&page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 5)
                ->where('entries.0.position', 26)
                ->where('entries.0.full_name', 'acme/repo-26')
                ->where('pagination.previous_url', '/discover?period=7d&language=Go&page=1')
                ->where('pagination.next_url', null)
            );
    }

    public function test_a_repository_gitchronicle_already_documents_opens_its_documentation(): void
    {
        $this->watch('Acme/Rocket', ['2026-11-11' => 1300]);
        $repository = Repository::factory()->ready()->create(['url' => 'https://github.com/acme/rocket', 'owner' => 'acme', 'name' => 'rocket']);

        $this->get('/discover')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.documentation_url', "/repositories/{$repository->id}")
                ->where('repository_of_the_day.documentation_url', "/repositories/{$repository->id}")
            );
    }

    public function test_documenteaza_as_a_guest_sends_to_login_and_the_repository_waits_in_the_session(): void
    {
        Bus::fake();
        $this->watch('Acme/Rocket', ['2026-11-11' => 1300]);
        $addUrl = $this->get('/discover')->inertiaProps('entries.0.add_url');

        $response = $this->post('/repositories', ['url' => $addUrl]);

        $response->assertRedirect('/login');
        $response->assertSessionHas(AddRepository::PENDING_URL_SESSION_KEY, 'https://github.com/acme/rocket');
        $this->assertDatabaseEmpty('repositories');
        Bus::assertNothingDispatched();
    }

    public function test_documenteaza_as_a_user_adds_the_repository_and_starts_its_processing(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $this->watch('Acme/Rocket', ['2026-11-11' => 1300]);
        $addUrl = $this->actingAs($user)->get('/discover')->inertiaProps('entries.0.add_url');

        $response = $this->actingAs($user)->post('/repositories', ['url' => $addUrl]);

        $repository = Repository::sole();
        $response->assertRedirect("/repositories/{$repository->id}");
        $this->assertSame(['acme', 'rocket'], [$repository->owner, $repository->name]);
        $this->assertTrue($repository->user->is($user));
        Bus::assertDispatched(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository) && $job->trigger === ProcessingTrigger::Add);
    }

    /**
     * A watched repository created on 2025-09-18 (419 days before 2026-11-11), with one snapshot per given day.
     *
     * @param  array<string, int>  $starsByDay
     */
    private function watch(string $fullName, array $starsByDay, ?string $language = 'Rust'): WatchedRepository
    {
        $repository = WatchedRepository::factory()->create([
            'full_name' => $fullName,
            'description' => 'Un motor de rachete.',
            'language' => $language,
            'github_created_at' => '2025-09-18 08:00:00',
        ]);

        foreach ($starsByDay as $day => $stars) {
            $repository->snapshots()->create(['captured_on' => $day, 'stars' => $stars, 'forks' => 0]);
        }

        return $repository;
    }
}
