<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingTrigger;
use App\Jobs\CloneRepository;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * The whole pipeline (ProcessRepository → CloneRepository → AnalyzeRepository → FinishProcessing)
 * with real git and no network: a local git repository stands in for GitHub and is cloned through
 * its folder path. The tests queue is "sync", so dispatch() runs the jobs right away, one after the other.
 * The tests that reach the analysis need the real analyzer (analyzer/.venv) and are skipped without it.
 */
class ProcessRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    public function test_pipeline_clones_and_analyzes_the_repository_and_marks_it_ready(): void
    {
        $this->skipUnlessAnalyzerIsInstalled();
        Storage::fake('local');
        $originPath = $this->createOriginRepository();
        $headSha = $this->git($originPath, ['rev-parse', 'HEAD']);
        $repository = Repository::factory()->create(['url' => $originPath]);
        $this->travelTo('2026-11-10 12:00:00.250');

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'status' => 'ready',
            'last_sha' => $headSha,
            'default_branch' => 'main',
            'cloned_at' => '2026-11-10 12:00:00',
            'analyzed_at' => '2026-11-10 12:00:00',
            'description' => null,
            'error' => null,
        ]);
        $this->assertDatabaseCount('processing_runs', 1);
        $this->assertDatabaseHas('processing_runs', [
            'repository_id' => $repository->id,
            'mode' => 'initial',
            'trigger' => 'manual',
            'status' => 'succeeded',
            'from_sha' => null,
            'to_sha' => $headSha,
            'files_total' => 2,
            'files_analyzed' => 2,
            'modules_total' => 1,
            'started_at' => '2026-11-10 12:00:00.250',
            'finished_at' => '2026-11-10 12:00:00.250',
            'duration_ms' => 0,
            'error' => null,
        ]);
        Storage::disk('local')->assertExists([
            "repositories/{$repository->id}/README.md",
            "repositories/{$repository->id}/app.php",
        ]);
        $this->assertSame(['README.md', 'app.php'], $repository->files()->orderBy('path')->pluck('path')->all());
    }

    public function test_pipeline_replaces_a_partial_clone_left_by_an_earlier_attempt(): void
    {
        $this->skipUnlessAnalyzerIsInstalled();
        Storage::fake('local');
        $originPath = $this->createOriginRepository();
        $repository = Repository::factory()->create(['url' => $originPath]);
        Storage::disk('local')->put("repositories/{$repository->id}/leftover.txt", 'a broken clone');

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
        Storage::disk('local')->assertMissing("repositories/{$repository->id}/leftover.txt");
        Storage::disk('local')->assertExists("repositories/{$repository->id}/README.md");
    }

    public function test_pipeline_marks_the_repository_and_the_run_failed_when_the_repository_does_not_exist(): void
    {
        Storage::fake('local');
        $repository = Repository::factory()->create(['url' => Storage::disk('local')->path('missing-origin')]);
        $message = 'Repository-ul nu există pe GitHub sau este privat. GitChronicle poate clona doar repository-uri publice.';

        ProcessRepository::dispatch($repository, ProcessingTrigger::Add);

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'status' => 'failed',
            'error' => $message,
            'last_sha' => null,
            'cloned_at' => null,
        ]);
        $this->assertDatabaseHas('processing_runs', [
            'repository_id' => $repository->id,
            'trigger' => 'add',
            'status' => 'failed',
            'error' => $message,
        ]);
        Storage::disk('local')->assertMissing("repositories/{$repository->id}");
    }

    public function test_pipeline_fails_with_a_clear_message_for_a_repository_without_commits(): void
    {
        Storage::fake('local');
        $originPath = Storage::disk('local')->path('origin');
        Storage::disk('local')->makeDirectory('origin');
        $this->git($originPath, ['init', '--quiet', '-b', 'main']);
        $repository = Repository::factory()->create(['url' => $originPath]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Add);

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'status' => 'failed',
            'error' => 'Repository-ul nu are încă niciun commit, deci nu avem ce documenta.',
        ]);
        $this->assertDatabaseHas('processing_runs', ['repository_id' => $repository->id, 'status' => 'failed']);
    }

    public function test_pipeline_does_not_start_a_second_run_while_one_is_running(): void
    {
        $repository = Repository::factory()->create();
        ProcessingRun::factory()->for($repository)->create();
        Bus::fake([CloneRepository::class]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $this->assertDatabaseCount('processing_runs', 1);
        Bus::assertNotDispatched(CloneRepository::class);
    }

    public function test_a_second_pipeline_is_not_queued_while_the_first_one_waits_in_the_queue(): void
    {
        $repository = Repository::factory()->create();
        Queue::fake([ProcessRepository::class]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Add);
        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        Queue::assertPushedTimes(ProcessRepository::class, 1);
    }

    /**
     * Create a git repository with two commits on "main" inside the fake "local" disk and return
     * its path. It plays the part of the GitHub repository.
     */
    private function createOriginRepository(): string
    {
        $originPath = Storage::disk('local')->path('origin');

        Storage::disk('local')->makeDirectory('origin');
        $this->git($originPath, ['init', '--quiet', '-b', 'main']);

        Storage::disk('local')->put('origin/README.md', "# Proiect de test\n");
        $this->git($originPath, ['add', 'README.md']);
        $this->git($originPath, ['commit', '--quiet', '-m', 'Primul commit']);

        Storage::disk('local')->put('origin/app.php', "<?php\n\necho 'Salut';\n");
        $this->git($originPath, ['add', 'app.php']);
        $this->git($originPath, ['commit', '--quiet', '-m', 'Al doilea commit']);

        return $originPath;
    }
}
