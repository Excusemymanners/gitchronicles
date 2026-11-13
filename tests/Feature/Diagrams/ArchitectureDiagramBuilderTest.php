<?php

namespace Tests\Feature\Diagrams;

use App\Analysis\RepositoryAnalyzer;
use App\Diagrams\ArchitectureDiagramBuilder;
use App\Models\FileImport;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * The architecture diagrams drawn from real data. The first tests analyze the Faza 5 fixture project
 * (tests/Fixtures/analyzer-project) with the real analyzer, after adding a few manifests to its copy
 * (React in package.json, PostgreSQL and Redis in .env.example, a compose file, the Stripe SDK),
 * and check the whole Mermaid code. The others build the analysis rows with factories.
 */
class ArchitectureDiagramBuilderTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    public function test_layers_map_draws_the_layers_with_what_their_links_do_and_how_many(): void
    {
        $diagrams = $this->buildForFixture();
        $mermaid = $diagrams['layers']['mermaid'];

        $this->assertStringContainsString('flowchart TB', $mermaid);
        $this->assertStringContainsString('layer_web["<b>Web/API</b><br>1 module"]', $mermaid);
        $this->assertMatchesRegularExpression('/layer_web -->\|"uses · \d+"\| layer_data/', $mermaid);
        $this->assertEveryNodeHasAnArrow($mermaid);
        $this->assertSame('Layers', $diagrams['layers']['meta']['title']);
    }

    public function test_containers_are_drawn_only_when_something_connects_them_and_the_others_are_listed(): void
    {
        $diagrams = $this->buildForFixture();
        $mermaid = $diagrams['container']['mermaid'];

        $this->assertStringContainsString('p1_ui -->|"HTTP requests"| p1_app', $mermaid);
        $this->assertStringContainsString('p1_app -->|"Reads and writes"| store_postgresql', $mermaid);
        $this->assertStringContainsString('p1_app -->|"API · HTTPS"| ext_stripe', $mermaid);
        $this->assertStringNotContainsString('store_meilisearch', $mermaid);
        $this->assertSame(['Meilisearch'], $diagrams['container']['meta']['unconnected']);
        $this->assertEveryNodeHasAnArrow($mermaid);
        $this->assertContains(['name' => 'Stripe', 'icon' => 'logos:stripe', 'source' => 'composer.json'], $diagrams['container']['meta']['technologies']);
    }

    public function test_dependency_map_names_the_modules_by_role_with_the_path_underneath_and_counts_the_links(): void
    {
        $diagrams = $this->buildForFixture();
        $mermaid = $diagrams['component']['mermaid'];

        $this->assertSame('Dependency map', $diagrams['component']['meta']['title']);
        $this->assertStringContainsString('<b>Http</b><br>src/Http"]', $mermaid);
        $this->assertStringNotContainsString('ProductController', $mermaid);
        $this->assertMatchesRegularExpression('/c\d+ -->\|"uses · \d+"\| c\d+/', $mermaid);
        $this->assertEveryNodeHasAnArrow($mermaid);
    }

    public function test_context_shows_the_user_the_system_and_what_it_calls(): void
    {
        $diagrams = $this->buildForFixture();

        $this->assertStringContainsString('system["<b>magazin</b><br>Un magazin mic, folosit ca exemplu în testele analizorului de cod."]', $diagrams['context']['mermaid']);
        $this->assertStringContainsString('user -->|"Uses"| system', $diagrams['context']['mermaid']);
        $this->assertStringContainsString('system -->|"Calls"| ext_stripe', $diagrams['context']['mermaid']);
    }

    public function test_escapes_folder_names_in_the_labels(): void
    {
        $repository = Repository::factory()->ready()->create();
        $http = $this->moduleWithCode($repository, 'app/Http', 40);
        $module = $this->moduleWithCode($repository, 'app/"Rău" <b>&#1', 30);
        FileImport::factory()->create(['repository_file_id' => $http->files()->first()->id, 'target_file_id' => $module->files()->first()->id, 'is_external' => false]);
        ModuleDependency::factory()->create(['repository_id' => $repository->id, 'from_module_id' => $http->id, 'to_module_id' => $module->id, 'weight' => 1]);

        $diagrams = $this->build($repository);

        $this->assertStringContainsString('["<b>#quot;Rău#quot; #lt;b#gt;#amp;#35;1</b><br>app/#quot;Rău#quot; #lt;b#gt;#amp;#35;1"]', $diagrams['component']['mermaid']);
    }

    public function test_dependency_map_keeps_the_12_most_linked_modules_and_lists_the_unlinked_ones(): void
    {
        $repository = Repository::factory()->ready()->create();
        $hub = $this->moduleWithCode($repository, 'app/Hub', 10);

        foreach (range(1, 14) as $number) {
            $module = $this->moduleWithCode($repository, sprintf('app/Module%02d', $number), 1000 + $number);

            if ($number <= 13) {
                ModuleDependency::factory()->create(['repository_id' => $repository->id, 'from_module_id' => $module->id, 'to_module_id' => $hub->id, 'weight' => $number]);
            }
        }

        $diagrams = $this->build($repository);
        $mermaid = $diagrams['component']['mermaid'];

        $this->assertStringContainsString('app/Hub', $mermaid);
        $this->assertStringNotContainsString('app/Module01"', $mermaid);
        $this->assertStringContainsString('app/Module13', $mermaid);
        $this->assertSame(['app/Module14'], $diagrams['component']['meta']['unconnected']);
        $this->assertSame(2, $diagrams['component']['meta']['omitted_modules']);
        $this->assertEveryNodeHasAnArrow($mermaid);
    }

    public function test_a_laravel_project_with_jobs_gets_a_queue_worker_fed_by_the_queue(): void
    {
        $repository = Repository::factory()->ready()->create(['name' => 'gitchronicles']);
        $this->cloneFiles($repository, [
            'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^13.17']]),
            '.env.example' => "DB_CONNECTION=sqlite\nQUEUE_CONNECTION=database\n",
            'app/Jobs/CloneRepository.php' => "<?php\n",
        ]);

        $diagrams = $this->build($repository);
        $mermaid = $diagrams['container']['mermaid'];

        $this->assertStringContainsString('p1_worker@{ icon: "logos:laravel", form: "rounded", label: "<b>Queue worker</b><br>Laravel · queue:work<br>Runs the jobs in the background", pos: "b", h: 44 }', $mermaid);
        $this->assertStringContainsString('p1_app -->|"Reads and writes"| store_sqlite', $mermaid);
        $this->assertStringContainsString('store_sqlite -->|"Hands out the jobs"| p1_worker', $mermaid);
        $this->assertEveryNodeHasAnArrow($mermaid);
    }

    /**
     * Every box of a flowchart is at one end of an arrow (the boundary group is not a box).
     */
    private function assertEveryNodeHasAnArrow(string $mermaid): void
    {
        preg_match_all('/^\s+(\w+)(?:\[|\(|@\{)/m', $mermaid, $nodes);
        preg_match_all('/^\s+(\w+) -->(?:\|[^|]*\|)? (\w+)/m', $mermaid, $edges);
        $boxes = array_diff($nodes[1], ['subgraph']);

        $this->assertSame([], array_values(array_diff($boxes, [...$edges[1], ...$edges[2]])), 'Boxes without an arrow');
    }

    public function test_a_repository_with_only_a_readme_gets_no_diagrams(): void
    {
        $repository = Repository::factory()->ready()->create();
        $module = Module::factory()->for($repository)->create(['path' => '/', 'name' => $repository->name]);
        RepositoryFile::factory()->for($repository)->for($module)->create(['path' => 'README.md', 'language' => 'Markdown']);

        $diagrams = $this->build($repository);

        $this->assertSame([], $diagrams);
    }

    /**
     * Copy the fixture project into the clone, add the manifests the tests look for, commit, analyze and build.
     *
     * @return array<string, array{mermaid: string, meta: array<string, mixed>}>
     */
    private function buildForFixture(): array
    {
        $this->skipUnlessAnalyzerIsInstalled();

        $repository = Repository::factory()->ready()->create(['name' => 'magazin']);
        $clonePath = $repository->clonePath();
        File::copyDirectory(base_path('tests/Fixtures/analyzer-project'), $clonePath);
        File::put("{$clonePath}/composer.json", json_encode([
            'name' => 'acme/shop',
            'require' => ['php' => '^8.3', 'stripe/stripe-php' => '^16.0'],
            'autoload' => ['psr-4' => ['Shop\\' => 'src/']],
        ]));
        File::put("{$clonePath}/package.json", json_encode([
            'dependencies' => ['react' => '^19.1.0'],
            'devDependencies' => ['typescript' => '^5.6.0', 'vite' => '^7.0.0'],
        ]));
        File::put("{$clonePath}/.env.example", "DB_CONNECTION=pgsql\nCACHE_STORE=redis\n# QUEUE_CONNECTION=redis\nMAIL_MAILER=log\n");
        File::put("{$clonePath}/docker-compose.yml", "services:\n  db:\n    image: postgres:16-alpine\n  search:\n    image: \"getmeili/meilisearch:v1.9\"\n  mail:\n    image: axllent/mailpit\n");
        $this->commitEverything($clonePath);
        app(RepositoryAnalyzer::class)->analyze($repository);

        return $this->build($repository->fresh());
    }

    /**
     * Build the diagrams and key them by type.
     *
     * @return array<string, array{mermaid: string, meta: array<string, mixed>}>
     */
    private function build(Repository $repository): array
    {
        $diagrams = app(ArchitectureDiagramBuilder::class)->build($repository);

        return collect($diagrams)->mapWithKeys(fn (array $diagram): array => [$diagram['type']->value => $diagram])->all();
    }

    /**
     * A module with one PHP file of the given number of lines.
     */
    private function moduleWithCode(Repository $repository, string $path, int $lines): Module
    {
        $module = Module::factory()->for($repository)->create(['path' => $path, 'name' => $path, 'file_count' => 1, 'line_count' => $lines]);
        RepositoryFile::factory()->for($repository)->for($module)->create(['path' => "{$path}/File.php", 'lines' => $lines]);

        return $module;
    }

    /**
     * Write files into the clone and record them as analyzed files (the detector reads both).
     *
     * @param  array<string, string>  $files  path => contents
     */
    private function cloneFiles(Repository $repository, array $files): void
    {
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$repository->clonePath()}/{$path}"));
            File::put("{$repository->clonePath()}/{$path}", $contents);
            RepositoryFile::factory()->for($repository)->create(['path' => $path, 'language' => null]);
        }
    }
}
