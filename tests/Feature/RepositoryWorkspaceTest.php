<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\TestCase;

/**
 * The workspace of a repository (WorkspaceResource): the chapters on the left, the „Elemente” cards
 * on the right and the assistant's starter questions, sent with every page of the repository.
 */
class RepositoryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProviderExternal(RepositoryTabsTest::class, 'tabs')]
    public function test_every_page_of_a_repository_gets_the_workspace(string $path, string $component): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}{$path}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('workspace.chapters')
            ->has('workspace.elements')
            ->has('workspace.assistant')
        );
    }

    public function test_a_repository_without_data_gets_only_the_overview_chapter_and_empty_elements(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('workspace', [
                'chapters' => [
                    [
                        'key' => 'docs',
                        'title' => 'Documentație',
                        'count' => null,
                        'chapters' => [['label' => 'Prezentare generală', 'href' => "/repositories/{$repository->id}", 'count' => null]],
                        'folders' => [],
                    ],
                ],
                'elements' => [
                    'architecture' => null,
                    'video' => null,
                    'slides' => null,
                    'activity' => null,
                    'hotspots' => null,
                ],
                'assistant' => ['is_indexed' => false, 'starters' => []],
            ])
        );
    }

    public function test_modules_are_grouped_by_top_folder_with_tool_folders_and_the_root_last(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $root = $this->module($repository, '/', 3);
        $workflows = $this->module($repository, '.github/workflows', 2);
        $http = $this->module($repository, 'app/Http', 9);
        $models = $this->module($repository, 'app/Models', 2);
        $config = $this->module($repository, 'config', 10);
        $database = $this->module($repository, 'database', 1);
        $migrations = $this->module($repository, 'database/migrations', 4);
        $this->module(Repository::factory()->create(), 'zzz/other', 99);

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('workspace.chapters.1.key', 'modules')
            ->where('workspace.chapters.1.title', 'Module')
            ->where('workspace.chapters.1.count', 7)
            ->where('workspace.chapters.1.folders', [
                ['name' => 'app', 'file_count' => 11, 'modules' => [
                    $this->moduleItem($repository, $http, 'Http'),
                    $this->moduleItem($repository, $models, 'Models'),
                ]],
                ['name' => 'config', 'file_count' => 10, 'modules' => [$this->moduleItem($repository, $config, 'config')]],
                ['name' => 'database', 'file_count' => 5, 'modules' => [
                    $this->moduleItem($repository, $database, 'database'),
                    $this->moduleItem($repository, $migrations, 'migrations'),
                ]],
                ['name' => '.github', 'file_count' => 2, 'modules' => [$this->moduleItem($repository, $workflows, 'workflows')]],
                ['name' => '/', 'file_count' => 3, 'modules' => [$this->moduleItem($repository, $root, '/')]],
            ])
        );
    }

    public function test_starter_questions_name_real_modules_and_skip_test_folders(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $http = $this->module($repository, 'app/Http', 9, lines: 400);
        $models = $this->module($repository, 'app/Models', 2, lines: 80);
        $actions = $this->module($repository, 'app/Actions', 1, lines: 60);
        $this->module($repository, 'config', 10, lines: 900);
        $this->module($repository, '/', 10, lines: 5000);
        $tests = $this->module($repository, 'tests/Feature', 5, lines: 3000);
        $this->dependency($repository, $tests, $models, 9);
        $this->dependency($repository, $http, $actions, 3);
        $this->dependency($repository, $http, $models, 2);
        $this->dependency($repository, $actions, $models, 2);

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('workspace.assistant.starters', [
                'Ce face modulul app/Models?',
                'Cum folosește app/Http modulul app/Actions?',
                'Ce conține modulul config?',
            ])
        );
    }

    public function test_generated_data_of_the_repository_adds_its_chapters_and_elements(): void
    {
        $this->createLaterPhaseTables();
        $repository = Repository::factory()->ready()->analyzed()->create();
        $http = $this->module($repository, 'app/Http', 9);
        $otherRepository = Repository::factory()->ready()->create();
        $this->document($repository, null, 'overview');
        $this->document($repository, null, 'readme');
        $this->document($repository, $http, 'app-http');
        $this->diagram($repository, 'layers', '', 'flowchart TB'."\n".'  web --> data');
        $this->diagram($repository, 'container');
        $this->diagram($repository, 'flow', 'adaugare');
        $this->diagram($repository, 'flow', 'procesare');
        $this->diagram($otherRepository, 'component');
        $this->commit($repository, 'Ana', '2026-10-27 10:00:00', ['README.md', 'app/Http/A.php']);
        $this->commit($repository, 'Ana', '2026-10-28 11:00:00', ['README.md', 'app/Http/A.php', 'routes/web.php', 'composer.lock']);
        $this->commit($repository, 'Dan', '2026-11-10 09:00:00', ['README.md', 'composer.lock']);
        $this->commit($otherRepository, 'Eva', '2026-11-11 09:00:00', ['README.md', 'other.php']);
        DB::table('code_chunks')->insert([
            'repository_id' => $repository->id,
            'repository_file_id' => RepositoryFile::factory()->for($repository)->create()->id,
            'start_line' => 1,
            'end_line' => 20,
            'symbol_name' => null,
            'content' => '<?php',
        ]);
        $base = "/repositories/{$repository->id}";

        $response = $this->get($base);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('workspace.chapters.0.chapters', [
                ['label' => 'Prezentare generală', 'href' => $base, 'count' => null],
                ['label' => 'Despre proiect', 'href' => "{$base}/docs", 'count' => null],
                ['label' => 'README generat', 'href' => "{$base}/docs/readme", 'count' => null],
            ])
            ->where('workspace.chapters.1.key', 'architecture')
            ->where('workspace.chapters.1.chapters', [
                ['label' => 'Cum funcționează', 'href' => "{$base}/diagrams#flow", 'count' => 2],
                ['label' => 'Straturi', 'href' => "{$base}/diagrams#layers", 'count' => null],
                ['label' => 'Containere (C4)', 'href' => "{$base}/diagrams#container", 'count' => null],
            ])
            ->where('workspace.chapters.2.folders.0.modules.0.href', "{$base}/docs/app-http")
            ->where('workspace.chapters.3.key', 'history')
            ->where('workspace.chapters.3.chapters', [
                ['label' => 'Commit-uri', 'href' => "{$base}/timeline#commits", 'count' => 3],
                ['label' => 'Zone fierbinți', 'href' => "{$base}/timeline#hotspots", 'count' => null],
                ['label' => 'Contribuitori', 'href' => "{$base}/timeline#contributors", 'count' => 2],
            ])
            ->where('workspace.elements.architecture', [
                'title' => 'Arhitectura pe straturi',
                'type' => 'layers',
                'mermaid' => 'flowchart TB'."\n".'  web --> data',
                'href' => "{$base}/diagrams#layers",
            ])
            ->where('workspace.elements.activity.commit_count', 3)
            ->where('workspace.elements.activity.contributor_count', 2)
            ->has('workspace.elements.activity.months', 12)
            ->where('workspace.elements.activity.href', "{$base}/timeline#commits")
            // The same numbers as the „Istoric” tab (HistoryStatistics): the lock file is left out.
            ->where('workspace.elements.hotspots.files', fn ($files): bool => collect($files)->pluck('commit_count', 'path')->all() === [
                'README.md' => 3,
                'app/Http/A.php' => 2,
                'routes/web.php' => 1,
            ])
            ->where('workspace.elements.hotspots.href', "{$base}/timeline#hotspots")
            ->where('workspace.assistant.is_indexed', true)
        );
    }

    public function test_the_status_poll_does_not_rebuild_the_workspace(): void
    {
        $repository = Repository::factory()->create();

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->has('workspace')
            ->reloadOnly(['repository', 'can_retry'], fn (Assert $reload) => $reload
                ->has('repository')
                ->missing('workspace')
            )
        );
    }

    public function test_the_workspace_runs_the_same_number_of_queries_for_any_number_of_modules(): void
    {
        $this->createLaterPhaseTables();
        $smallRepository = Repository::factory()->ready()->analyzed()->create();
        $this->module($smallRepository, 'app/Http', 1);
        $bigRepository = Repository::factory()->ready()->analyzed()->create();

        foreach (range(1, 40) as $number) {
            $module = $this->module($bigRepository, 'folder'.($number % 8).'/module'.$number, 1);
            $this->document($bigRepository, $module, 'module-'.$number);
        }

        $smallQueries = $this->countQueries(fn () => $this->get("/repositories/{$smallRepository->id}")->assertOk());
        $bigQueries = $this->countQueries(fn () => $this->get("/repositories/{$bigRepository->id}")->assertOk());

        $this->assertSame($smallQueries, $bigQueries);
    }

    private function module(Repository $repository, string $path, int $files, int $lines = 100): Module
    {
        return Module::factory()->for($repository)->create([
            'path' => $path,
            'name' => $path,
            'file_count' => $files,
            'line_count' => $lines,
        ]);
    }

    /**
     * @return array{id: int, path: string, label: string, file_count: int, href: string}
     */
    private function moduleItem(Repository $repository, Module $module, string $label): array
    {
        return [
            'id' => $module->id,
            'path' => $module->path,
            'label' => $label,
            'file_count' => $module->file_count,
            'href' => "/repositories/{$repository->id}#modul-{$module->id}",
        ];
    }

    private function dependency(Repository $repository, Module $from, Module $to, int $weight): void
    {
        ModuleDependency::factory()->for($repository)->create([
            'from_module_id' => $from->id,
            'to_module_id' => $to->id,
            'weight' => $weight,
        ]);
    }

    /**
     * The tables written by the later phases (documentation, diagrams, history, assistant), with the
     * columns from the shared data model. Once those phases are merged their migrations create the
     * tables, and this only fills the gap before that.
     */
    private function createLaterPhaseTables(): void
    {
        if (! Schema::hasTable('documents')) {
            Schema::create('documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('repository_id');
                $table->foreignId('module_id')->nullable();
                $table->string('slug');
                $table->string('title');
                $table->longText('markdown');
                $table->string('source_sha', 40);
                $table->string('input_hash', 64);
                $table->string('writer', 20);
                $table->timestamp('generated_at');
                $table->boolean('is_stale')->default(false);
            });
        }

        if (! Schema::hasTable('diagrams')) {
            Schema::create('diagrams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('repository_id');
                $table->string('type', 20);
                $table->string('key')->default('');
                $table->text('mermaid');
                $table->json('meta')->nullable();
                $table->string('source_sha', 40);
            });
        }

        if (! Schema::hasTable('commits')) {
            Schema::create('commits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('repository_id');
                $table->string('sha', 40);
                $table->string('parent_sha', 40)->nullable();
                $table->string('author_name');
                $table->timestamp('authored_at');
                $table->string('subject');
                $table->unsignedInteger('files_changed');
                $table->unsignedInteger('insertions');
                $table->unsignedInteger('deletions');
            });
        }

        if (! Schema::hasTable('commit_files')) {
            Schema::create('commit_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commit_id');
                $table->string('path', 1024);
                $table->unsignedInteger('insertions');
                $table->unsignedInteger('deletions');
            });
        }

        if (! Schema::hasTable('code_chunks')) {
            Schema::create('code_chunks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('repository_id');
                $table->foreignId('repository_file_id');
                $table->unsignedInteger('start_line');
                $table->unsignedInteger('end_line');
                $table->string('symbol_name')->nullable();
                $table->text('content');
            });
        }
    }

    private function document(Repository $repository, ?Module $module, string $slug): void
    {
        DB::table('documents')->insert([
            'repository_id' => $repository->id,
            'module_id' => $module?->id,
            'slug' => $slug,
            'title' => $slug,
            'markdown' => "# {$slug}",
            'source_sha' => str_repeat('a', 40),
            'input_hash' => str_repeat('b', 64),
            'writer' => 'template',
            'generated_at' => now(),
            'is_stale' => false,
        ]);
    }

    private function diagram(Repository $repository, string $type, string $key = '', string $mermaid = 'flowchart TB'): void
    {
        DB::table('diagrams')->insert([
            'repository_id' => $repository->id,
            'type' => $type,
            'key' => $key,
            'mermaid' => $mermaid,
            'meta' => null,
            'source_sha' => str_repeat('a', 40),
        ]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function commit(Repository $repository, string $author, string $authoredAt, array $paths): void
    {
        $commitId = DB::table('commits')->insertGetId([
            'repository_id' => $repository->id,
            'sha' => fake()->sha1(),
            'parent_sha' => null,
            'author_name' => $author,
            'authored_at' => $authoredAt,
            'subject' => 'Schimbare',
            'files_changed' => count($paths),
            'insertions' => 1,
            'deletions' => 0,
        ]);

        foreach ($paths as $path) {
            DB::table('commit_files')->insert(['commit_id' => $commitId, 'path' => $path, 'insertions' => 1, 'deletions' => 0]);
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
