<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use App\Git\CommitHistory;
use App\Jobs\AnalyzeRepository;
use App\Jobs\ImportCommits;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * ImportCommits with real git: the clone is a local repository written straight into the
 * repository's clone folder (storage/app/private/repositories/{id} on the fake "local" disk).
 */
class ImportCommitsTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    public function test_imports_the_commits_and_the_files_they_changed(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->addCommit($repository, 'app.php', "<?php\n", 'Primul commit');
        $this->addCommit($repository, 'app.php', "<?php\n\necho 'Salut';\n", 'Salută vizitatorul');
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->importCommits($repository, $run);

        $this->assertDatabaseCount('commits', 2);
        $this->assertDatabaseHas('commits', [
            'repository_id' => $repository->id,
            'sha' => $this->git($repository->clonePath(), ['rev-parse', 'HEAD']),
            'parent_sha' => $this->git($repository->clonePath(), ['rev-parse', 'HEAD~1']),
            'is_merge' => false,
            'author_name' => 'Ana Popescu',
            'subject' => 'Salută vizitatorul',
            'files_changed' => 1,
            'insertions' => 2,
            'deletions' => 0,
        ]);
        $this->assertDatabaseCount('commit_files', 2);
        $this->assertDatabaseHas('commit_files', ['path' => 'app.php', 'insertions' => 2, 'deletions' => 0]);
    }

    public function test_importing_the_same_history_again_changes_nothing(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->addCommit($repository, 'app.php', "<?php\n", 'Primul commit');
        $this->addCommit($repository, 'README.md', "# Proiect\n", 'Adaugă README');
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->importCommits($repository, $run);
        $this->importCommits($repository, $run);

        $this->assertDatabaseCount('commits', 2);
        $this->assertDatabaseCount('commit_files', 2);
    }

    public function test_a_later_import_reads_only_the_commits_after_the_last_processed_one(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->addCommit($repository, 'app.php', "<?php\n", 'Primul commit');
        $firstRun = ProcessingRun::factory()->for($repository)->create();
        $this->importCommits($repository, $firstRun);
        $firstRun->update(['to_sha' => $this->git($repository->clonePath(), ['rev-parse', 'HEAD'])]);
        $firstRun->finish(ProcessingRunStatus::Succeeded);
        // If the old commit were read again, the import would write its real subject back.
        $repository->commits()->update(['subject' => 'Marcat de test']);
        $this->addCommit($repository, 'README.md', "# Proiect\n", 'Adaugă README');
        $secondRun = ProcessingRun::factory()->for($repository)->create();

        $this->importCommits($repository, $secondRun);

        $this->assertSame(['Adaugă README', 'Marcat de test'], $repository->commits()->orderByDesc('id')->pluck('subject')->all());
    }

    public function test_reads_everything_again_when_the_last_processed_commit_was_never_imported(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->addCommit($repository, 'app.php', "<?php\n", 'Primul commit');
        $this->addCommit($repository, 'README.md', "# Proiect\n", 'Adaugă README');
        // A run from before the history was imported: it processed HEAD, but no commit is in the database.
        ProcessingRun::factory()->for($repository)->create(['to_sha' => $this->git($repository->clonePath(), ['rev-parse', 'HEAD'])])
            ->finish(ProcessingRunStatus::Succeeded);
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->importCommits($repository, $run);

        $this->assertDatabaseCount('commits', 2);
    }

    public function test_the_import_runs_in_the_pipeline_right_after_the_code_analysis(): void
    {
        $analysisPosition = array_search(AnalyzeRepository::class, ProcessRepository::STAGES, true);

        $this->assertSame(ImportCommits::class, ProcessRepository::STAGES[$analysisPosition + 1]);
    }

    public function test_the_pipeline_imports_the_history_of_the_cloned_repository(): void
    {
        $this->skipUnlessAnalyzerIsInstalled();
        $originPath = Storage::disk('local')->path('origin');
        Storage::disk('local')->put('origin/README.md', "# Proiect de test\n");
        $this->commitEverything($originPath, 'Primul commit');
        $repository = Repository::factory()->create(['url' => $originPath]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Add);

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
        $this->assertDatabaseHas('commits', [
            'repository_id' => $repository->id,
            'sha' => $this->git($originPath, ['rev-parse', 'HEAD']),
            'subject' => 'Primul commit',
            'insertions' => 1,
        ]);
    }

    /**
     * Write a file into the repository's clone folder and commit it (the first call creates the git repository).
     */
    private function addCommit(Repository $repository, string $file, string $contents, string $message): void
    {
        $path = $repository->clonePath();

        if (! Storage::disk('local')->exists("{$repository->cloneDirectory()}/.git")) {
            Storage::disk('local')->makeDirectory($repository->cloneDirectory());
            $this->git($path, ['init', '--quiet', '-b', 'main']);
        }

        Storage::disk('local')->put("{$repository->cloneDirectory()}/{$file}", $contents);
        $this->git($path, ['add', '--all']);
        $this->git($path, ['commit', '--quiet', '-m', $message]);
    }

    private function importCommits(Repository $repository, ProcessingRun $run): void
    {
        (new ImportCommits($repository, $run))->handle(app(CommitHistory::class));
    }
}
