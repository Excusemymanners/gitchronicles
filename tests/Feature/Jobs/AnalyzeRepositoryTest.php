<?php

namespace Tests\Feature\Jobs;

use App\Analysis\RepositoryAnalyzer;
use App\Exceptions\RepositoryProcessingException;
use App\Jobs\AnalyzeRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * What AnalyzeRepository does with the analyzer's answers. git and the analyzer are faked here
 * (Process::fake), so these tests run without Python; RepositoryAnalyzerTest runs the real analyzer.
 * The analyzer's Python only has to be an existing file (PHP_BINARY stands in for it): it never runs.
 */
class AnalyzeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What `git ls-files` answers for a repository with two files and a picture.
     */
    private const TRACKED_FILES = "100644 0123456789abcdef0123456789abcdef01234567 120 lf app/Http/HomeController.php\0"
        ."100644 89abcdef0123456789abcdef0123456789abcdef 80 lf app/Models/User.php\0"
        ."100644 fedcba9876543210fedcba9876543210fedcba98 5000 -text public/logo.png\0";

    public function test_saves_the_analysis_and_records_its_counters_on_the_run(): void
    {
        config(['services.analyzer.python' => PHP_BINARY]);
        Process::fake(fn (PendingProcess $process) => $process->command[0] === 'git'
            ? Process::result(output: self::TRACKED_FILES)
            : Process::result(output: json_encode(['files' => [
                $this->analyzedFile('app/Http/HomeController.php', imports: [['raw' => 'App\Models\User', 'resolved_path' => 'app/Models/User.php', 'external' => false]]),
                $this->analyzedFile('app/Models/User.php'),
            ], 'errors' => []])));
        $repository = Repository::factory()->ready()->create();
        $run = ProcessingRun::factory()->for($repository)->create();

        (new AnalyzeRepository($repository, $run))->handle(app(RepositoryAnalyzer::class));

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'analyzing']);
        $this->assertDatabaseHas('processing_runs', [
            'id' => $run->id,
            'files_total' => 3,
            'files_analyzed' => 2,
            'modules_total' => 2,
        ]);
        $this->assertDatabaseHas('module_dependencies', ['repository_id' => $repository->id, 'weight' => 1]);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            PHP_BINARY, '-I', base_path('analyzer/analyze.py'), $repository->clonePath(), '--files-from', '-',
            '--cache', Storage::disk('local')->path("analysis-cache/{$repository->id}.json"),
        ]
            && $process->input === "app/Http/HomeController.php\napp/Models/User.php"
            && $process->timeout === 240);
    }

    public function test_fails_with_a_clear_message_when_the_analyzer_is_not_installed(): void
    {
        config(['services.analyzer.python' => base_path('analyzer/missing/bin/python')]);
        Process::fake(['*' => Process::result(output: self::TRACKED_FILES)]);
        $repository = Repository::factory()->ready()->create();
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->assertThrows(
            fn () => (new AnalyzeRepository($repository, $run))->handle(app(RepositoryAnalyzer::class)),
            RepositoryProcessingException::class,
            StoredMessage::make('repositories.errors.analyzer_missing'),
        );
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'analyzed_at' => null]);
    }

    public function test_fails_with_a_clear_message_when_the_analyzer_stops_with_an_error(): void
    {
        config(['services.analyzer.python' => PHP_BINARY]);
        Process::fake(fn (PendingProcess $process) => $process->command[0] === 'git'
            ? Process::result(output: self::TRACKED_FILES)
            : Process::result(errorOutput: 'Traceback (most recent call last): ...', exitCode: 1));
        $repository = Repository::factory()->ready()->create();
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->assertThrows(
            fn () => (new AnalyzeRepository($repository, $run))->handle(app(RepositoryAnalyzer::class)),
            RepositoryProcessingException::class,
            StoredMessage::make('repositories.errors.analysis_failed'),
        );
        $this->assertDatabaseCount('repository_files', 0);
        $this->assertDatabaseHas('processing_runs', ['id' => $run->id, 'files_analyzed' => 0]);
    }

    public function test_fails_with_a_clear_message_when_the_analysis_takes_too_long(): void
    {
        config(['services.analyzer.python' => PHP_BINARY]);
        Process::fake(fn (PendingProcess $process) => $process->command[0] === 'git'
            ? Process::result(output: self::TRACKED_FILES)
            : new SymfonyTimeoutException(new SymfonyProcess(['python']), SymfonyTimeoutException::TYPE_GENERAL));
        $repository = Repository::factory()->ready()->create();
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->assertThrows(
            fn () => (new AnalyzeRepository($repository, $run))->handle(app(RepositoryAnalyzer::class)),
            RepositoryProcessingException::class,
            StoredMessage::make('repositories.errors.analysis_timeout', ['minutes' => 4]),
        );
    }

    /**
     * One file in the analyzer's JSON answer.
     *
     * @param  list<array{raw: string, resolved_path: string|null, external: bool}>  $imports
     * @return array<string, mixed>
     */
    private function analyzedFile(string $path, array $imports = []): array
    {
        return ['path' => $path, 'language' => 'PHP', 'lines' => 10, 'symbols' => [], 'imports' => $imports];
    }
}
