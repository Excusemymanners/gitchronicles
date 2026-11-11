<?php

namespace Tests\Feature;

use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Jobs\ProcessRepository;
use App\Models\Commit;
use App\Models\Document;
use App\Models\Module;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Regenerează tot” (POST /repositories/{id}/regenerate) and the props that drive the empty states of the
 * chapters ("can_regenerate", "completeness").
 */
class RegenerateButtonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function regenerableStatuses(): array
    {
        return ['ready' => ['ready'], 'failed' => ['failed']];
    }

    #[DataProvider('regenerableStatuses')]
    public function test_any_logged_in_user_starts_a_full_processing_of_a_ready_or_failed_repository(string $status): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create(['status' => $status]);

        $this->actingAs(User::factory()->create())
            ->from(route('repositories.docs', $repository))
            ->post(route('repositories.regenerate', $repository))
            ->assertRedirect(route('repositories.docs', $repository))
            ->assertInertiaFlash('success', 'Am pornit procesarea completă: toate capitolele se fac din nou.');

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository)
            && $job->trigger === ProcessingTrigger::Manual
            && $job->full === true);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'pending', 'error' => null]);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();

        $this->post(route('repositories.regenerate', $repository))->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }

    public function test_a_repository_that_is_waiting_or_working_cannot_be_regenerated(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        foreach ([RepositoryStatus::Pending, RepositoryStatus::Cloning, RepositoryStatus::Updating] as $status) {
            $this->actingAs($user)
                ->post(route('repositories.regenerate', Repository::factory()->ready()->create(['status' => $status])))
                ->assertForbidden();
        }

        Queue::assertNothingPushed();
    }

    public function test_the_button_is_rate_limited_like_adding_a_repository(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $repository = Repository::factory()->ready()->create();

        // The first request starts the run; the next ones are refused by the policy (the repository is
        // pending now), but they count for the limit too.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($user)->post(route('repositories.regenerate', $repository));
        }

        $this->actingAs($user)->post(route('repositories.regenerate', $repository))->assertTooManyRequests();
        Queue::assertPushed(ProcessRepository::class, 1);
    }

    public function test_the_pages_say_who_may_regenerate(): void
    {
        $ready = Repository::factory()->ready()->create();

        $this->get(route('repositories.show', $ready))
            ->assertInertia(fn (Assert $page) => $page->where('can_regenerate', false));

        $this->actingAs(User::factory()->create());

        $this->get(route('repositories.timeline', $ready))
            ->assertInertia(fn (Assert $page) => $page->where('can_regenerate', true));
        $this->get(route('repositories.show', Repository::factory()->failed()->create()))
            ->assertInertia(fn (Assert $page) => $page->where('can_regenerate', true));
        $this->get(route('repositories.show', Repository::factory()->create()))
            ->assertInertia(fn (Assert $page) => $page->where('can_regenerate', false));
    }

    public function test_the_pages_of_an_incomplete_repository_say_what_is_missing(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $module = Module::factory()->for($repository)->create(['path' => 'src', 'name' => 'src']);
        RepositoryFile::factory()->for($repository)->create(['module_id' => $module->id, 'path' => 'src/app.py', 'language' => 'Python']);

        foreach (['repositories.show', 'repositories.docs', 'repositories.diagrams', 'repositories.assistant', 'repositories.timeline'] as $route) {
            $this->get(route($route, $repository))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('completeness.is_incomplete', true)
                    ->where('completeness.missing', ['history', 'documentation', 'diagrams', 'assistant'])
                    ->where('completeness.missing_text', 'istoricul, documentația, diagramele și indexul asistentului')
                    ->where('completeness.heals_automatically', true)
                    ->where('completeness.artifacts.structure.state', 'ready')
                    ->where('completeness.artifacts.documentation.state', 'missing')
                    ->where('completeness.artifacts.video.state', 'missing'));
        }
    }

    public function test_a_repository_without_source_code_says_its_diagrams_and_index_cannot_exist(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $root = Module::factory()->for($repository)->create(['path' => '/', 'name' => '/']);
        RepositoryFile::factory()->for($repository)->create(['module_id' => $root->id, 'path' => 'README', 'language' => null]);
        Commit::factory()->for($repository)->create(['sha' => $repository->last_sha]);
        Document::factory()->for($repository)->create(['slug' => Document::OVERVIEW]);

        $this->get(route('repositories.diagrams', $repository))
            ->assertInertia(fn (Assert $page) => $page
                ->where('completeness.is_incomplete', false)
                ->where('completeness.missing', [])
                ->where('completeness.artifacts.diagrams.state', 'not_applicable')
                ->where('completeness.artifacts.assistant.state', 'not_applicable'));
    }

    public function test_the_status_poll_does_not_build_the_completeness_report(): void
    {
        $repository = Repository::factory()->ready()->create();

        $this->get(route('repositories.show', $repository))
            ->assertInertia(fn (Assert $page) => $page
                ->has('completeness')
                ->reloadOnly('repository,can_retry', fn (Assert $reload) => $reload->missing('completeness')->has('repository')));
    }
}
