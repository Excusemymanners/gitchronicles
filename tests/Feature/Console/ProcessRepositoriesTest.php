<?php

namespace Tests\Feature\Console;

use App\Enums\ProcessingTrigger;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_restarts_one_failed_repository_by_id(): void
    {
        $repository = Repository::factory()->failed()->create(['owner' => 'octocat', 'name' => 'hello-world']);
        Bus::fake([ProcessRepository::class]);

        $this->artisan('repositories:process', ['repository' => $repository->id])
            ->expectsOutput("#{$repository->id} octocat/hello-world: procesarea a pornit.")
            ->assertSuccessful();

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'pending', 'error' => null]);
        Bus::assertDispatched(ProcessRepository::class, fn (ProcessRepository $job) => $job->repository->is($repository)
            && $job->trigger === ProcessingTrigger::Manual);
    }

    public function test_all_pending_starts_every_pending_repository_and_nothing_else(): void
    {
        $firstPending = Repository::factory()->create();
        $secondPending = Repository::factory()->create();
        Repository::factory()->ready()->create();
        Repository::factory()->failed()->create();
        Bus::fake([ProcessRepository::class]);

        $this->artisan('repositories:process', ['--all-pending' => true])->assertSuccessful();

        Bus::assertDispatchedTimes(ProcessRepository::class, 2);
        Bus::assertDispatched(ProcessRepository::class, fn (ProcessRepository $job) => $job->repository->is($firstPending));
        Bus::assertDispatched(ProcessRepository::class, fn (ProcessRepository $job) => $job->repository->is($secondPending));
    }

    public function test_skips_a_repository_whose_run_is_still_running(): void
    {
        $repository = Repository::factory()->create(['owner' => 'octocat', 'name' => 'hello-world']);
        ProcessingRun::factory()->for($repository)->create();
        Bus::fake([ProcessRepository::class]);

        $this->artisan('repositories:process', ['repository' => $repository->id])
            ->expectsOutput("#{$repository->id} octocat/hello-world is being processed right now, leaving it alone.")
            ->assertSuccessful();

        Bus::assertNotDispatched(ProcessRepository::class);
    }

    public function test_fails_for_an_unknown_id(): void
    {
        Bus::fake([ProcessRepository::class]);

        $this->artisan('repositories:process', ['repository' => 999])
            ->expectsOutput('There is no repository with id 999.')
            ->assertFailed();

        Bus::assertNotDispatched(ProcessRepository::class);
    }

    public function test_all_incomplete_processes_in_full_every_ready_repository_that_misses_an_artifact(): void
    {
        $incomplete = Repository::factory()->ready()->analyzed()->create(['owner' => 'octocat', 'name' => 'hello-world']);
        $complete = Repository::factory()->complete()->create();
        $failed = Repository::factory()->failed()->create();
        $pending = Repository::factory()->create();
        Bus::fake([ProcessRepository::class]);

        $this->artisan('repositories:process', ['--all-incomplete' => true])
            ->expectsOutput("#{$incomplete->id} octocat/hello-world: missing history and documentation, the full processing started.")
            ->expectsOutputToContain('Queued 1 repository.')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ProcessRepository::class, 1);
        Bus::assertDispatched(ProcessRepository::class, fn (ProcessRepository $job) => $job->repository->is($incomplete)
            && $job->trigger === ProcessingTrigger::Manual
            && $job->full === true);
        $this->assertDatabaseHas('repositories', ['id' => $incomplete->id, 'status' => 'pending']);
        $this->assertDatabaseHas('repositories', ['id' => $complete->id, 'status' => 'ready']);
        $this->assertDatabaseHas('repositories', ['id' => $failed->id, 'status' => 'failed']);
        $this->assertDatabaseHas('repositories', ['id' => $pending->id, 'status' => 'pending']);
    }

    public function test_all_incomplete_says_so_when_nothing_is_missing(): void
    {
        Repository::factory()->complete()->create();
        Bus::fake([ProcessRepository::class]);

        $this->artisan('repositories:process', ['--all-incomplete' => true])
            ->expectsOutput('No ready repository has missing chapters.')
            ->assertSuccessful();

        Bus::assertNotDispatched(ProcessRepository::class);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function ambiguousArguments(): array
    {
        return [
            'neither an id nor --all-pending' => [[]],
            'both an id and --all-pending' => [['repository' => 1, '--all-pending' => true]],
            'both an id and --all-incomplete' => [['repository' => 1, '--all-incomplete' => true]],
            'both --all-pending and --all-incomplete' => [['--all-pending' => true, '--all-incomplete' => true]],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('ambiguousArguments')]
    public function test_fails_unless_given_exactly_one_of_an_id_or_all_pending(array $arguments): void
    {
        Repository::factory()->create();
        Bus::fake([ProcessRepository::class]);

        $this->artisan('repositories:process', $arguments)
            ->expectsOutput('Give either a repository id (repositories:process 5), or --all-pending, or --all-incomplete.')
            ->assertFailed();

        Bus::assertNotDispatched(ProcessRepository::class);
    }
}
