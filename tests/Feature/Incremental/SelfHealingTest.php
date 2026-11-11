<?php

namespace Tests\Feature\Incremental;

use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An incomplete repository never answers „nimic nou”: „Verifică actualizări” and the scheduled check
 * start a full processing for it, even at an unchanged commit (the webhook is in GitHubWebhookTest).
 * git is faked (Process::fake): GitHub is never called from tests.
 */
class SelfHealingTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_check_updates_starts_a_full_processing_for_an_incomplete_repository(): void
    {
        Queue::fake();
        $repository = $this->incompleteRepository();

        $this->actingAs(User::factory()->create())
            ->post(route('repositories.check-updates', $repository))
            ->assertRedirect();

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository)
            && $job->trigger === ProcessingTrigger::Manual
            && $job->full === true);
    }

    public function test_check_updates_stays_incremental_for_a_complete_repository(): void
    {
        Queue::fake();
        $repository = Repository::factory()->complete()->create();

        $this->actingAs(User::factory()->create())->post(route('repositories.check-updates', $repository));

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->full === false);
    }

    public function test_the_scheduled_check_heals_an_incomplete_repository_at_the_same_commit(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result(output: self::SHA."\tHEAD\n")]);
        $repository = $this->incompleteRepository();

        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain("#{$repository->id} acme/magazin: lipsesc istoricul, documentația, diagramele și indexul asistentului, am pornit o procesare completă.")
            ->expectsOutputToContain('Verificate: 1. Actualizări pornite: 1.')
            ->assertSuccessful();

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository)
            && $job->trigger === ProcessingTrigger::Schedule
            && $job->full === true);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'updating']);
    }

    public function test_the_scheduled_check_does_not_repeat_a_full_processing_at_the_same_commit(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result(output: self::SHA."\tHEAD\n")]);
        $repository = $this->incompleteRepository();
        ProcessingRun::factory()->for($repository)->create([
            'mode' => ProcessingMode::Full,
            'status' => ProcessingRunStatus::Succeeded,
            'to_sha' => self::SHA,
            'started_at' => now()->subDay(),
        ]);

        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain("#{$repository->id} acme/magazin: la zi (commitul aaaaaaa), dar lipsesc")
            ->expectsOutputToContain('Actualizări pornite: 0.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_scheduled_check_heals_only_after_the_minimum_interval(): void
    {
        config(['gitchronicle.incremental.min_interval_minutes' => 10]);
        Queue::fake();
        Process::fake(['*' => Process::result(output: self::SHA."\tHEAD\n")]);
        $repository = $this->incompleteRepository();
        ProcessingRun::factory()->for($repository)->create(['status' => ProcessingRunStatus::Succeeded, 'to_sha' => self::SHA, 'started_at' => now()->subMinutes(2)]);

        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain('dar ultima actualizare a pornit de curând; îl iau la verificarea următoare.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_scheduled_check_gives_an_incomplete_repository_with_a_new_commit_a_full_processing(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result(output: str_repeat('b', 40)."\tHEAD\n")]);
        $repository = $this->incompleteRepository();

        $this->artisan('repositories:check-updates')
            ->expectsOutputToContain("#{$repository->id} acme/magazin: commit nou (bbbbbbb), am pornit o procesare completă.")
            ->assertSuccessful();

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->full === true);
    }

    /**
     * A ready repository processed by an older pipeline: only the code structure, no history, documentation,
     * diagrams or assistant index, with a run that succeeded at its commit a day ago.
     */
    private function incompleteRepository(): Repository
    {
        $repository = Repository::factory()->ready()->analyzed()->create(['owner' => 'acme', 'name' => 'magazin', 'last_sha' => self::SHA]);
        $module = $repository->modules()->create(['path' => 'src', 'name' => 'src', 'file_count' => 1, 'line_count' => 10]);
        $repository->files()->create(['module_id' => $module->id, 'path' => 'src/app.py', 'language' => 'Python', 'lines' => 10, 'size_bytes' => 100, 'blob_sha' => str_repeat('d', 40)]);
        ProcessingRun::factory()->for($repository)->create([
            'status' => ProcessingRunStatus::Succeeded,
            'to_sha' => self::SHA,
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        return $repository;
    }
}
