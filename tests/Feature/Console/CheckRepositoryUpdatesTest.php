<?php

namespace Tests\Feature\Console;

use App\Enums\ProcessingTrigger;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * repositories:check-updates with git faked (Process::fake): GitHub is never called from tests.
 */
class CheckRepositoryUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const NEW_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_a_repository_with_a_new_commit_on_github_gets_an_update_with_the_schedule_trigger(): void
    {
        Queue::fake();
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result(output: self::NEW_SHA."\tHEAD\n")]);
        $repository = Repository::factory()->complete()->create(['url' => 'https://github.com/acme/magazin', 'owner' => 'acme', 'name' => 'magazin', 'last_sha' => self::OLD_SHA]);

        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain("#{$repository->id} acme/magazin: commit nou (bbbbbbb), am pornit actualizarea.")
            ->expectsOutputToContain('Verificate: 1. Actualizări pornite: 1.')
            ->assertSuccessful();

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository) && $job->trigger === ProcessingTrigger::Schedule);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'updating']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'git', '-c', 'credential.helper=', '-c', 'core.askPass=', 'ls-remote', '--', 'https://github.com/acme/magazin', 'HEAD',
        ]
            && $process->environment === ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '']
            && $process->timeout === 20);
    }

    public function test_a_repository_updated_recently_waits_for_a_later_check(): void
    {
        config(['gitchronicle.incremental.min_interval_minutes' => 10]);
        Queue::fake();
        Process::fake(['*' => Process::result(output: self::NEW_SHA."\tHEAD\n")]);
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'last_sha' => self::OLD_SHA]);
        ProcessingRun::factory()->for($repository)->create(['status' => 'succeeded', 'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinute()]);

        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain("#{$repository->id} acme/magazin: commit nou (bbbbbbb), dar ultima actualizare a pornit de curând; îl iau la verificarea următoare.")
            ->expectsOutputToContain('Verificate: 1. Actualizări pornite: 0.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
    }

    public function test_a_repository_that_is_up_to_date_is_left_alone(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result(output: self::OLD_SHA."\tHEAD\n")]);
        $repository = Repository::factory()->complete()->create(['owner' => 'acme', 'name' => 'magazin', 'last_sha' => self::OLD_SHA]);

        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain("#{$repository->id} acme/magazin: la zi (commitul aaaaaaa).")
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
        $this->assertDatabaseCount('processing_runs', 0);
    }

    public function test_when_github_does_not_answer_nothing_is_started(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result(errorOutput: 'fatal: unable to access', exitCode: 128)]);
        Repository::factory()->ready()->create(['last_sha' => self::OLD_SHA]);

        $this->artisan('repositories:check-updates')->expectsOutputToContain('GitHub nu a răspuns')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_only_ready_repositories_are_checked_and_one_can_be_chosen(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result(output: self::NEW_SHA."\tHEAD\n")]);
        $first = Repository::factory()->ready()->create(['last_sha' => self::OLD_SHA]);
        $second = Repository::factory()->ready()->create(['last_sha' => self::OLD_SHA]);
        Repository::factory()->failed()->create(['last_sha' => self::OLD_SHA]);
        Repository::factory()->create();

        $this->artisan('repositories:check-updates', ['repository' => $second->id])
            ->expectsOutputToContain('Verificate: 1. Actualizări pornite: 1.')
            ->assertSuccessful();
        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain('Verificate: 1. Actualizări pornite: 1.')
            ->assertSuccessful();

        Queue::assertPushed(ProcessRepository::class, 2);
        $this->assertSame('updating', $first->fresh()->status->value);
    }

    public function test_the_check_is_scheduled_with_the_configured_cron_expression(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'repositories:check-updates'));

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()->expression);
    }
}
