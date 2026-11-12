<?php

namespace Tests\Feature\Diagrams;

use App\Analysis\RepositoryAnalyzer;
use App\Diagrams\ArchitectureDiagramBuilder;
use App\Models\CodeSymbol;
use App\Models\Module;
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

    public function test_layers_view_groups_the_fixture_modules_by_folder_names_with_one_arrow_per_pair_of_layers(): void
    {
        $diagrams = $this->buildForFixture();

        $this->assertSame(<<<'MERMAID'
            ---
            config:
              layout: elk
              elk:
                nodePlacementStrategy: SIMPLE
                cycleBreakingStrategy: GREEDY_MODEL_ORDER
              flowchart:
                curve: linear
                wrappingWidth: 240
            ---
            flowchart TB
              subgraph layer_entry["Entry points"]
                m8["tools/cli"]
              end
              subgraph layer_ui["User interface"]
                m1["frontend"]
                m2["frontend/components"]
                m3["frontend/lib"]
              end
              subgraph layer_web["Web/API"]
                m4["src/Http"]
              end
              subgraph layer_logic["Application logic"]
                m6["src/Support"]
              end
              subgraph layer_data["Data"]
                m5["src/Models"]
              end
              subgraph layer_other["Other"]
                m7["tools"]
              end
              layer_entry ~~~ layer_ui
              layer_ui ~~~ layer_web
              layer_web ~~~ layer_logic
              layer_logic ~~~ layer_data
              layer_data ~~~ layer_other
              layer_entry --> layer_other
              layer_web --> layer_logic
              layer_web --> layer_data
              layer_data --> layer_logic
              classDef layer_entry stroke:#8b5cf6,stroke-width:1.5px
              classDef layer_ui stroke:#0ea5e9,stroke-width:1.5px
              classDef layer_web stroke:#6366f1,stroke-width:1.5px
              classDef layer_logic stroke:#10b981,stroke-width:1.5px
              classDef layer_data stroke:#f59e0b,stroke-width:1.5px
              classDef layer_other stroke:#9ca3af,stroke-width:1.5px
              class m8 layer_entry
              class m1,m2,m3 layer_ui
              class m4 layer_web
              class m6 layer_logic
              class m5 layer_data
              class m7 layer_other

            MERMAID, $diagrams['layers']['mermaid']);
        $this->assertSame([['label' => 'Files in the project root', 'paths' => ['/']]], $diagrams['layers']['meta']['excluded']);
        $this->assertSame('Layered architecture', $diagrams['layers']['meta']['title']);
    }

    public function test_container_view_draws_only_the_technologies_found_in_the_manifests_with_their_logos(): void
    {
        $diagrams = $this->buildForFixture();

        $this->assertSame(<<<'MERMAID'
            ---
            config:
              layout: elk
              flowchart:
                curve: linear
                wrappingWidth: 240
            ---
            flowchart LR
              subgraph system["<b>magazin</b>"]
                p1_app@{ icon: "logos:php", form: "rounded", label: "<b>magazin</b><br>PHP ^8.3", pos: "b", h: 44 }
                p1_ui@{ icon: "logos:react", form: "rounded", label: "<b>Web interface</b><br>React 19 · TypeScript<br>Pages in the browser", pos: "b", h: 44 }
                store_postgresql@{ icon: "logos:postgresql", form: "rounded", label: "<b>Database</b><br>PostgreSQL", pos: "b", h: 44 }
                store_redis@{ icon: "logos:redis", form: "rounded", label: "Redis", pos: "b", h: 44 }
                store_meilisearch@{ icon: "logos:meilisearch", form: "rounded", label: "Meilisearch", pos: "b", h: 44 }
              end
              user@{ shape: person, label: "<b>User</b><br>Uses the application" }
              ext_stripe@{ icon: "logos:stripe", form: "rounded", label: "<b>Stripe</b><br>External system", pos: "b", h: 44 }
              p1_ui -->|"HTTP requests"| p1_app
              user -->|"Uses · HTTPS"| p1_ui
              p1_app -->|"Reads and writes"| store_postgresql
              p1_app -->|"Cache"| store_redis
              p1_app -->|"API · HTTPS"| ext_stripe
              classDef person fill:#08427b,stroke:#073b6f,color:#ffffff
              classDef container fill:#e8f1fb
              classDef external fill:#e5e7eb
              classDef boundary fill:none,stroke:#6b7280,stroke-dasharray:6 4
              class system boundary
              class p1_app,p1_ui,store_postgresql,store_redis,store_meilisearch container
              class user person
              class ext_stripe external

            MERMAID, $diagrams['container']['mermaid']);
        $this->assertSame([
            ['name' => 'PHP ^8.3', 'icon' => 'logos:php', 'source' => 'composer.json'],
            ['name' => 'Stripe', 'icon' => 'logos:stripe', 'source' => 'composer.json'],
            ['name' => 'React 19', 'icon' => 'logos:react', 'source' => 'package.json'],
            ['name' => 'TypeScript', 'icon' => 'logos:typescript-icon', 'source' => 'package.json'],
            ['name' => 'Vite', 'icon' => 'logos:vitejs', 'source' => 'package.json'],
            ['name' => 'PostgreSQL', 'icon' => 'logos:postgresql', 'source' => '.env.example'],
            ['name' => 'Redis', 'icon' => 'logos:redis', 'source' => '.env.example'],
            ['name' => 'Docker', 'icon' => 'logos:docker-icon', 'source' => 'docker-compose.yml'],
            ['name' => 'Meilisearch', 'icon' => 'logos:meilisearch', 'source' => 'docker-compose.yml'],
        ], $diagrams['container']['meta']['technologies']);
    }

    public function test_component_view_shows_the_modules_with_their_main_classes_and_dependencies(): void
    {
        $diagrams = $this->buildForFixture();

        $this->assertSame(<<<'MERMAID'
            ---
            config:
              layout: elk
              flowchart:
                curve: linear
                wrappingWidth: 240
            ---
            flowchart TB
              subgraph system["<b>magazin</b> · code components"]
                c1["frontend"]
                c2["<b>components</b><br>frontend/components<br>ProductCard<br>Props"]
                c3["frontend/lib"]
                c4["<b>Http</b><br>src/Http<br>ProductController"]
                c5["<b>Models</b><br>src/Models<br>Product<br>Category"]
                c6["<b>Support</b><br>src/Support<br>Money"]
                c7["<b>tools</b><br>Stats"]
                c8["<b>cli</b><br>tools/cli"]
                c4 --> c5
                c1 --> c2
                c1 --> c3
                c2 --> c3
                c4 --> c6
                c5 --> c6
                c8 --> c7
              end
              classDef component fill:#2563eb,stroke:#1d4ed8,color:#ffffff
              classDef boundary fill:none,stroke:#6b7280,stroke-dasharray:6 4
              class system boundary
              class c1,c2,c3,c4,c5,c6,c7,c8 component

            MERMAID, $diagrams['component']['mermaid']);
        $this->assertSame('The modules with code, with their main classes; an arrow means that a module imports code from another.', $diagrams['component']['meta']['caption']);
    }

    public function test_context_view_shows_the_user_the_system_and_the_external_systems_the_code_calls(): void
    {
        $diagrams = $this->buildForFixture();

        $this->assertStringContainsString('system["<b>magazin</b><br>Un magazin mic, folosit ca exemplu în testele analizorului de cod."]', $diagrams['context']['mermaid']);
        $this->assertStringContainsString('user -->|"Uses"| system', $diagrams['context']['mermaid']);
        $this->assertStringContainsString('system -->|"Sends requests"| ext_stripe', $diagrams['context']['mermaid']);
    }

    public function test_escapes_folder_and_class_names_in_the_labels(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->moduleWithCode($repository, 'app/Http', 40);
        $module = $this->moduleWithCode($repository, 'app/"Rău" <b>&#1', 30);
        CodeSymbol::factory()->create(['repository_file_id' => $module->files()->first()->id, 'kind' => 'class', 'name' => 'Clasa"Rea`']);

        $diagrams = $this->build($repository);

        $this->assertStringContainsString('m1["app/#quot;Rău#quot; #lt;b#gt;#amp;#35;1"]', $diagrams['layers']['mermaid']);
        $this->assertStringContainsString('c1["<b>#quot;Rău#quot; #lt;b#gt;#amp;#35;1</b><br>app/#quot;Rău#quot; #lt;b#gt;#amp;#35;1<br>Clasa#quot;Rea#96;"]', $diagrams['component']['mermaid']);
    }

    public function test_component_view_keeps_the_15_biggest_modules_and_counts_the_others_in_one_box(): void
    {
        $repository = Repository::factory()->ready()->create();

        foreach (range(1, 17) as $number) {
            $this->moduleWithCode($repository, sprintf('app/Module%02d', $number), 100 + $number);
        }

        $diagrams = $this->build($repository);

        $this->assertStringContainsString('more["+ 2 more modules"]', $diagrams['component']['mermaid']);
        $this->assertStringNotContainsString('app/Module01', $diagrams['component']['mermaid']);
        $this->assertStringNotContainsString('app/Module02', $diagrams['component']['mermaid']);
        $this->assertStringContainsString('<b>Module03</b>', $diagrams['component']['mermaid']);
        $this->assertSame(2, $diagrams['component']['meta']['omitted_modules']);
        $this->assertStringStartsWith('The 15 biggest of the 17 modules with code', $diagrams['component']['meta']['caption']);
    }

    public function test_layers_view_shows_at_most_six_modules_per_layer_and_counts_the_others(): void
    {
        $repository = Repository::factory()->ready()->create();

        foreach (range(1, 8) as $number) {
            $this->moduleWithCode($repository, "app/Logic{$number}", 10 * $number);
        }

        $diagrams = $this->build($repository);

        $this->assertStringContainsString('more_logic["+ 2 more modules"]', $diagrams['layers']['mermaid']);
        $this->assertStringNotContainsString('"app/Logic1"', $diagrams['layers']['mermaid']);
        $this->assertStringNotContainsString('"app/Logic2"', $diagrams['layers']['mermaid']);
        $this->assertStringContainsString('m6["app/Logic8"]', $diagrams['layers']['mermaid']);
    }

    public function test_a_laravel_project_with_jobs_gets_a_queue_worker_that_reads_the_database(): void
    {
        $repository = Repository::factory()->ready()->create(['name' => 'gitchronicles']);
        $this->cloneFiles($repository, [
            'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^13.17']]),
            '.env.example' => "DB_CONNECTION=sqlite\nQUEUE_CONNECTION=database\n",
            'app/Jobs/CloneRepository.php' => "<?php\n",
        ]);

        $diagrams = $this->build($repository);

        $this->assertStringContainsString('p1_worker@{ icon: "logos:laravel", form: "rounded", label: "<b>Queue worker</b><br>Laravel · queue:work<br>Runs the jobs in the background", pos: "b", h: 44 }', $diagrams['container']['mermaid']);
        $this->assertStringContainsString('p1_app -->|"Reads and writes"| store_sqlite', $diagrams['container']['mermaid']);
        $this->assertStringContainsString('p1_worker -->|"Takes the jobs"| store_sqlite', $diagrams['container']['mermaid']);
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
