<?php

namespace Tests\Unit\Analysis;

use App\Analysis\LinkScanner;
use PHPUnit\Framework\TestCase;

/**
 * The scan of the connections the imports do not show, on small projects written to a temporary folder:
 * for each language a process call, a script path, a URL host, an SDK import and a queued job, and next
 * to them a comment or a string that must not match.
 */
class LinkScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/link-scanner-'.bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    public function test_php_laravel_links_are_found_with_their_kind_and_line(): void
    {
        $links = $this->scan([
            'routes/web.php' => <<<'PHP'
                <?php
                use App\Http\Controllers\RepositoryController;
                Route::post('/repositories', [RepositoryController::class, 'store']);
                PHP,
            'app/Http/Controllers/RepositoryController.php' => <<<'PHP'
                <?php
                namespace App\Http\Controllers;
                use App\Jobs\ProcessRepository;
                class RepositoryController extends Controller
                {
                    public function store()
                    {
                        ProcessRepository::dispatch(1);
                        // Inertia::render('repositories/old') is only a comment
                        return Inertia::render('repositories/show', []);
                    }
                }
                PHP,
            'app/Http/Controllers/Controller.php' => "<?php\nnamespace App\\Http\\Controllers;\nabstract class Controller {}\n",
            'app/Jobs/ProcessRepository.php' => <<<'PHP'
                <?php
                namespace App\Jobs;
                use Illuminate\Contracts\Queue\ShouldQueue;
                class ProcessRepository implements ShouldQueue
                {
                    public const STAGES = [CloneRepository::class];
                    public function handle() { Bus::chain([]); }
                }
                PHP,
            'app/Jobs/CloneRepository.php' => "<?php\nnamespace App\\Jobs;\nclass CloneRepository implements ShouldQueue {}\n",
            'resources/js/pages/repositories/show.tsx' => "export default function Show() { return null; }\n",
            'resources/js/pages/repositories/old.tsx' => "export default function Old() { return null; }\n",
        ]);

        $this->assertContains(['routes/web.php', 3, 'route', 'app/Http/Controllers/RepositoryController.php'], $links);
        $this->assertContains(['app/Http/Controllers/RepositoryController.php', 4, 'reference', 'app/Http/Controllers/Controller.php'], $links);
        $this->assertContains(['app/Http/Controllers/RepositoryController.php', 8, 'dispatch', 'app/Jobs/ProcessRepository.php'], $links);
        $this->assertContains(['app/Http/Controllers/RepositoryController.php', 8, 'queues', 'store:queue'], $links);
        $this->assertContains(['app/Http/Controllers/RepositoryController.php', 10, 'render', 'resources/js/pages/repositories/show.tsx'], $links);
        $this->assertContains(['app/Jobs/ProcessRepository.php', 6, 'dispatch', 'app/Jobs/CloneRepository.php'], $links);
        $this->assertNotContains(['app/Http/Controllers/RepositoryController.php', 9, 'render', 'resources/js/pages/repositories/old.tsx'], $links);
    }

    public function test_php_programs_scripts_services_and_stores_are_found(): void
    {
        $links = $this->scan([
            'config/tools.php' => "<?php\nreturn ['claude_bin' => env('CLAUDE_BIN', 'claude'), 'analyzer' => base_path('analyzer/analyze.py')];\n",
            'app/Providers/AppServiceProvider.php' => <<<'PHP'
                <?php
                namespace App\Providers;
                use App\Ai\CliGenerator;
                class AppServiceProvider
                {
                    public function register()
                    {
                        $this->app->bind('ai', fn () => new CliGenerator(
                            binary: config('tools.claude_bin'),
                        ));
                    }
                }
                PHP,
            'app/Ai/CliGenerator.php' => <<<'PHP'
                <?php
                namespace App\Ai;
                use Anthropic\Client;
                class CliGenerator
                {
                    public function __construct(private string $binary) {}
                    public function run() { return Process::run([$this->binary, '-p']); }
                }
                PHP,
            'app/Analysis/Analyzer.php' => <<<'PHP'
                <?php
                namespace App\Analysis;
                class Analyzer
                {
                    public function run()
                    {
                        Log::info('git is not a program here');
                        Process::run(['git', 'ls-files']);
                        Process::run([$python, base_path('analyzer/analyze.py')]);
                        $url = Http::get('https://api.github.com/repos/a/b');
                        // https://api.example.com is a comment
                        return DB::table('files')->get();
                    }
                }
                PHP,
            'app/Models/File.php' => "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass File extends Model {}\n",
            'analyzer/analyze.py' => "print('hello')\n",
        ], imports: ['app/Ai/CliGenerator.php' => ['Anthropic\\Client']]);

        $this->assertContains(['app/Ai/CliGenerator.php', 7, 'runs', 'tool:claude'], $links);
        $this->assertContains(['app/Ai/CliGenerator.php', 3, 'calls', 'service:anthropic'], $links);
        $this->assertContains(['app/Analysis/Analyzer.php', 8, 'runs', 'tool:git'], $links);
        $this->assertContains(['app/Analysis/Analyzer.php', 9, 'runs', 'analyzer/analyze.py'], $links);
        $this->assertContains(['app/Analysis/Analyzer.php', 10, 'calls', 'service:github'], $links);
        $this->assertContains(['app/Analysis/Analyzer.php', 12, 'stores', 'store:database'], $links);
        $this->assertContains(['app/Models/File.php', 4, 'stores', 'store:database'], $links);
        $this->assertNotContains(['app/Analysis/Analyzer.php', 7, 'runs', 'tool:git'], $links);
        $this->assertSame([], array_filter($links, fn (array $link): bool => $link[1] === 11));
        $this->assertSame([], array_filter($links, fn (array $link): bool => $link[0] === 'app/Providers/AppServiceProvider.php' && $link[2] === 'runs'));
    }

    public function test_javascript_links_are_found(): void
    {
        $links = $this->scan([
            'src/app.ts' => <<<'TS'
                import { spawn } from 'node:child_process';
                import Anthropic from '@anthropic-ai/sdk';
                import { queue } from 'bullmq';
                const pages = import.meta.glob('./pages/**/*.tsx');
                spawn('ffmpeg', ['-i', input]);
                // spawn('git', ['status']) is only a comment
                spawn('node', ['scripts/build.mjs']);
                const answer = await fetch('https://api.openai.com/v1/chat');
                const text = 'see https://docs.example.com for more';
                TS,
            'src/pages/home.tsx' => "export default function Home() { return null; }\n",
            'scripts/build.mjs' => "console.log('build');\n",
        ], imports: ['src/app.ts' => ['node:child_process', '@anthropic-ai/sdk', 'bullmq']]);

        $this->assertContains(['src/app.ts', 2, 'calls', 'service:anthropic'], $links);
        $this->assertContains(['src/app.ts', 3, 'queues', 'store:queue'], $links);
        $this->assertContains(['src/app.ts', 4, 'loads', 'src/pages/home.tsx'], $links);
        $this->assertContains(['src/app.ts', 5, 'runs', 'tool:ffmpeg'], $links);
        $this->assertContains(['src/app.ts', 8, 'calls', 'service:openai'], $links);
        $this->assertNotContains(['src/app.ts', 6, 'runs', 'tool:git'], $links);
        $this->assertSame([], array_filter($links, fn (array $link): bool => $link[1] === 9));
    }

    public function test_python_shell_rust_and_go_links_are_found(): void
    {
        $links = $this->scan([
            'tools/run.py' => <<<'PY'
                import subprocess
                import requests
                """
                subprocess.run(["git", "status"]) inside a docstring
                """
                subprocess.run(["semgrep", "--json"])
                requests.get("https://api.github.com/user")
                # subprocess.run(["trivy"]) is a comment
                PY,
            'bin/install.sh' => <<<'SH'
                #!/bin/sh
                # curl https://nodejs.org is a comment
                if command -v curl > /dev/null; then
                  curl -fsSL "https://nodejs.org/dist/index.json"
                fi
                echo "wget is only a word here"
                ./scripts/setup.sh
                SH,
            'scripts/setup.sh' => "echo ok\n",
            'src/main.rs' => "use std::process::Command;\nfn main() { Command::new(\"git\").arg(\"pull\"); }\n",
            'cmd/server/main.go' => "package main\nimport \"os/exec\"\nfunc main() { exec.Command(\"docker\", \"ps\") }\n",
        ], languages: ['tools/run.py' => 'Python', 'bin/install.sh' => 'Shell', 'scripts/setup.sh' => 'Shell', 'src/main.rs' => 'Rust', 'cmd/server/main.go' => 'Go']);

        $this->assertContains(['tools/run.py', 6, 'runs', 'tool:semgrep'], $links);
        $this->assertContains(['tools/run.py', 7, 'calls', 'service:github'], $links);
        $this->assertContains(['bin/install.sh', 4, 'runs', 'tool:curl'], $links);
        $this->assertContains(['bin/install.sh', 4, 'calls', 'Node.js downloads'], array_map(fn (array $link): array => [$link[0], $link[1], $link[2], str_replace('service:', '', $link[3])], $links));
        $this->assertContains(['bin/install.sh', 7, 'runs', 'scripts/setup.sh'], $links);
        $this->assertContains(['src/main.rs', 2, 'runs', 'tool:git'], $links);
        $this->assertContains(['cmd/server/main.go', 3, 'runs', 'tool:docker'], $links);
        $this->assertSame([], array_filter($links, fn (array $link): bool => in_array($link[1], [2, 4, 8], true) && $link[0] === 'tools/run.py'));
        $this->assertNotContains(['bin/install.sh', 6, 'runs', 'tool:wget'], $links);
    }

    public function test_a_workspace_package_imported_by_name_points_to_its_entry_file(): void
    {
        $links = $this->scan([
            'packages/core/package.json' => '{"name": "@acme/core", "main": "dist/index.js"}',
            'packages/core/src/index.ts' => "export const x = 1;\n",
            'packages/cli/src/main.ts' => "import { x } from '@acme/core';\n",
        ], imports: ['packages/cli/src/main.ts' => ['@acme/core']]);

        $this->assertContains(['packages/cli/src/main.ts', 1, 'import', 'packages/core/src/index.ts'], $links);
    }

    /**
     * Write the files, scan them and return the links as [from, line, kind, to or target].
     *
     * @param  array<string, string>  $files  path => contents
     * @param  array<string, list<string>>  $imports  path => external imports, as the analyzer would give them
     * @param  array<string, string>  $languages  path => language, when the extension is not enough
     * @return list<array{string, int, string, string}>
     */
    private function scan(array $files, array $imports = [], array $languages = []): array
    {
        $analyzed = [];

        foreach ($files as $path => $contents) {
            @mkdir(dirname("{$this->root}/{$path}"), 0777, true);
            file_put_contents("{$this->root}/{$path}", $contents);

            $analyzed[] = [
                'path' => $path,
                'language' => $languages[$path] ?? match (pathinfo($path, PATHINFO_EXTENSION)) {
                    'php' => 'PHP', 'ts', 'tsx' => 'TypeScript', 'js', 'mjs' => 'JavaScript', 'py' => 'Python', default => null,
                },
                'imports' => array_map(fn (string $raw): array => ['raw' => $raw, 'resolved_path' => null, 'external' => true], $imports[$path] ?? []),
            ];
        }

        return array_map(
            fn (array $link): array => [$link['from'], $link['line'], $link['kind'], $link['to'] ?? $link['target']],
            (new LinkScanner)->scan($this->root, $analyzed),
        );
    }
}
