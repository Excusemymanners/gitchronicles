<?php

namespace Tests\Feature\Analysis;

use App\Analysis\RepositoryAnalyzer;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * The code analysis of repositories built to hurt it: a symbolic link out of the clone, files named like options,
 * paths with "..", files that are too big or have too many symbols, a tsconfig made to make a regex crawl, a file
 * name that is not UTF-8. Every test uses the real analyzer and is skipped without it.
 */
class HostileAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessAnalyzerIsInstalled();
        Storage::fake('local');
    }

    public function test_a_go_mod_that_is_a_link_to_a_file_outside_the_clone_is_not_read(): void
    {
        $secret = sys_get_temp_dir().'/gc-secret-'.bin2hex(random_bytes(4)).'.mod';
        File::put($secret, "module secret.example/leaked\n");
        $repository = $this->repositoryWith(function (string $path) use ($secret): void {
            symlink($secret, "{$path}/go.mod");
            File::put("{$path}/main.go", "package main\n\nimport \"secret.example/leaked/util\"\n\nfunc main() {}\n");
        });

        app(RepositoryAnalyzer::class)->analyze($repository);

        // If go.mod were followed, the import would be "inside the module" (external = false).
        $this->assertTrue((bool) $repository->files()->where('path', 'main.go')->first()->imports()->first()->is_external);
        File::delete($secret);
    }

    public function test_a_file_named_like_an_option_is_analyzed_as_any_other_file(): void
    {
        $repository = $this->repositoryWith(function (string $path): void {
            File::put("{$path}/--files-from", "def a():\n    pass\n");
            File::put("{$path}/-r.py", "def b():\n    pass\n");
        });

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(2, $summary['files_analyzed']);
        $this->assertEqualsCanonicalizing(['-r.py'], $repository->files()->where('language', 'Python')->pluck('path')->all());
    }

    public function test_a_path_that_leaves_the_clone_or_a_link_is_refused_by_the_analyzer_itself(): void
    {
        $outside = sys_get_temp_dir().'/gc-outside-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($outside);
        File::put("{$outside}/passwd", "root:x:0:0\n");
        $clone = sys_get_temp_dir().'/gc-clone-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($clone);
        symlink("{$outside}/passwd", "{$clone}/link");
        symlink($outside, "{$clone}/linked-folder");

        $answer = $this->runAnalyzer($clone, ['../'.basename($outside).'/passwd', 'link', 'linked-folder/passwd']);

        $this->assertSame([], $answer['files']);
        $this->assertCount(3, $answer['errors']);
        File::deleteDirectory($outside);
        File::deleteDirectory($clone);
    }

    public function test_a_file_over_the_size_limit_is_not_read(): void
    {
        $clone = sys_get_temp_dir().'/gc-clone-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($clone);
        File::put("{$clone}/big.py", str_repeat("x = 1\n", 1000));

        $answer = $this->runAnalyzer($clone, ['big.py'], ['--max-file-bytes', '500']);

        $this->assertSame([], $answer['files']);
        $this->assertStringContainsString('larger than 500 bytes', $answer['errors'][0]['message']);
        File::deleteDirectory($clone);
    }

    public function test_a_file_with_thousands_of_symbols_is_cut_and_the_numbers_left_out_are_reported(): void
    {
        config(['gitchronicle.analysis.max_symbols_per_file' => 100, 'gitchronicle.analysis.max_imports_per_file' => 10]);
        $repository = $this->repositoryWith(function (string $path): void {
            $functions = collect(range(1, 400))->map(fn (int $i) => "def f{$i}(): pass")->implode("\n");
            $imports = collect(range(1, 30))->map(fn (int $i) => "import module{$i}")->implode("\n");
            File::put("{$path}/generated.py", $imports."\n".$functions."\n");
        });

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(['files' => 0, 'symbols' => 300, 'imports' => 20], $summary['left_out']);
        $this->assertSame(100, $repository->files()->first()->symbols()->count());
        $this->assertSame(10, $repository->files()->first()->imports()->count());
    }

    public function test_more_files_than_the_limit_are_left_out_and_counted(): void
    {
        config(['gitchronicle.analysis.max_files' => 3]);
        $repository = $this->repositoryWith(function (string $path): void {
            foreach (range(1, 8) as $i) {
                File::put("{$path}/m{$i}.py", "def f{$i}(): pass\n");
            }
        });

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(3, $summary['files_analyzed']);
        $this->assertSame(5, $summary['left_out']['files']);
    }

    public function test_a_tsconfig_of_unclosed_comments_does_not_make_the_analysis_crawl(): void
    {
        $repository = $this->repositoryWith(function (string $path): void {
            File::put("{$path}/tsconfig.json", '{"compilerOptions": {}}'.str_repeat('/*', 120_000));
            File::put("{$path}/a.ts", "export const a = 1;\n");
        });

        $start = microtime(true);
        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(2, $summary['files_analyzed']);
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function test_a_file_name_that_is_not_utf8_does_not_stop_the_analysis_of_the_others(): void
    {
        $repository = $this->repositoryWith(function (string $path): void {
            File::put("{$path}/caf\xE9.py", "def old(): pass\n");
            File::put("{$path}/good.py", "def new(): pass\n");
        });

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(['good.py'], $repository->files()->pluck('path')->all());
        $this->assertSame(1, $summary['files_analyzed']);
    }

    public function test_a_parse_that_takes_too_long_is_stopped_for_that_file_only(): void
    {
        $clone = sys_get_temp_dir().'/gc-clone-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($clone);
        File::put("{$clone}/deep.py", str_repeat('a=(', 300_000));
        File::put("{$clone}/ok.py", "def fine(): pass\n");

        $answer = $this->runAnalyzer($clone, ['deep.py', 'ok.py'], ['--seconds-per-file', '0.05']);

        $this->assertSame(['ok.py'], array_column($answer['files'], 'path'));
        $this->assertSame('TimeoutError: parsing took too long', $answer['errors'][0]['message']);
        File::deleteDirectory($clone);
    }

    public function test_a_shebang_line_of_endless_digits_does_not_make_the_language_detection_crawl(): void
    {
        $clone = sys_get_temp_dir().'/gc-clone-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($clone);
        File::put("{$clone}/run", '#!/usr/bin/env '.str_repeat('1', 400_000)."x\necho hi\n");

        $start = microtime(true);
        $answer = $this->runAnalyzer($clone, ['run']);

        $this->assertNull($answer['files'][0]['language']);
        $this->assertLessThan(3.0, microtime(true) - $start);
        File::deleteDirectory($clone);
    }

    /**
     * A repository whose clone folder holds the files $fill creates, committed with git.
     */
    private function repositoryWith(callable $fill): Repository
    {
        $repository = Repository::factory()->ready()->create();
        File::ensureDirectoryExists($repository->clonePath());
        $fill($repository->clonePath());
        $this->commitEverything($repository->clonePath());

        return $repository;
    }

    /**
     * Run analyzer/analyze.py directly on a folder and a list of paths (also the ones the PHP side would not send).
     *
     * @param  list<string>  $paths
     * @param  list<string>  $options
     * @return array{files: list<array<string, mixed>>, errors: list<array{path: string, message: string}>, left_out: array<string, int>}
     */
    private function runAnalyzer(string $clone, array $paths, array $options = []): array
    {
        $result = Process::input(implode("\n", $paths))->run([
            config('services.analyzer.python'), '-I', base_path('analyzer/analyze.py'), $clone, '--files-from', '-', ...$options,
        ])->throw();

        return json_decode($result->output(), true);
    }
}
