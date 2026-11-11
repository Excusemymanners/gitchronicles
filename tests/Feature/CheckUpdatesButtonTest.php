<?php

namespace Tests\Feature;

use App\Enums\ProcessingTrigger;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * „Verifică actualizări” (POST /repositories/{id}/check-updates) and what the repository pages
 * know about the last update ("can_check_updates", "last_update").
 */
class CheckUpdatesButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_logged_in_user_starts_an_update_of_a_ready_repository(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();

        $response = $this->actingAs(User::factory()->create())
            ->from(route('repositories.timeline', $repository))
            ->post(route('repositories.check-updates', $repository));

        $response->assertRedirect(route('repositories.timeline', $repository));
        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository) && $job->trigger === ProcessingTrigger::Manual);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'updating']);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();

        $this->post(route('repositories.check-updates', $repository))->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }

    public function test_a_repository_that_is_not_ready_cannot_be_checked(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('repositories.check-updates', Repository::factory()->failed()->create()))->assertForbidden();
        $this->actingAs($user)->post(route('repositories.check-updates', Repository::factory()->create()))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_pages_say_who_may_check_and_when_the_repository_was_last_updated(): void
    {
        $repository = Repository::factory()->ready()->create();
        ProcessingRun::factory()->for($repository)->create(['status' => 'succeeded', 'to_sha' => str_repeat('a', 40), 'finished_at' => '2026-11-10 10:00:00']);
        ProcessingRun::factory()->for($repository)->create(['status' => 'skipped', 'mode' => 'incremental', 'to_sha' => str_repeat('a', 40), 'finished_at' => '2026-11-10 11:30:00']);
        ProcessingRun::factory()->for($repository)->create(['status' => 'failed', 'to_sha' => str_repeat('b', 40), 'finished_at' => '2026-11-10 12:00:00']);

        $this->get(route('repositories.show', $repository))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_check_updates', false)
                ->where('last_update.sha', str_repeat('a', 40))
                ->where('last_update.nothing_new', true)
                ->where('last_update.finished_at', '2026-11-10T11:30:00+00:00'));

        $this->actingAs(User::factory()->create())
            ->get(route('repositories.docs', $repository))
            ->assertInertia(fn (Assert $page) => $page->where('can_check_updates', true));
    }

    public function test_a_repository_never_processed_has_no_last_update(): void
    {
        $this->get(route('repositories.show', Repository::factory()->create()))
            ->assertInertia(fn (Assert $page) => $page->where('last_update', null)->where('can_check_updates', false));
    }
}
