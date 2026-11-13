<?php

namespace Tests\Feature;

use App\Enums\DiagramType;
use App\Models\Diagram;
use App\Models\Document;
use App\Models\Module;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The „Diagrame” tab and the architecture preview on „Prezentare”, as a guest sees them.
 */
class DiagramsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_flows_and_every_architecture_view_of_the_repository(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'excusemymanners', 'name' => 'gitchronicles']);
        $sha = $repository->last_sha;
        Diagram::factory()->for($repository)->create(['source_sha' => $sha, 'meta' => [
            'title' => 'Arhitectura pe straturi',
            'caption' => 'Modulele grupate pe straturi.',
            'excluded' => [['label' => 'Teste', 'paths' => ['tests']]],
        ]]);
        Diagram::factory()->for($repository)->create(['type' => DiagramType::Container, 'source_sha' => $sha, 'meta' => [
            'title' => 'Containere',
            'caption' => 'Părțile care rulează separat.',
            'technologies' => [['name' => 'Laravel 13', 'icon' => 'logos:laravel', 'source' => 'composer.json']],
        ]]);
        Diagram::factory()->flow()->for($repository)->create(['source_sha' => $sha]);

        $response = $this->get("/repositories/{$repository->id}/diagrams");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/diagrams')
            ->where('diagrams.layers.title', 'Arhitectura pe straturi')
            ->where('diagrams.layers.excluded', [['label' => 'Teste', 'paths' => ['tests']]])
            ->where('diagrams.container.technologies.0.name', 'Laravel 13')
            ->where('diagrams.component', null)
            ->where('diagrams.context', null)
            ->has('diagrams.flows', 1)
            ->where('diagrams.flows.0.title', 'Adding a repository')
            ->where('diagrams.flows.0.sources.0.url', "https://github.com/excusemymanners/gitchronicles/blob/{$sha}/app/Http/Controllers/RepositoryController.php#L10-L20")
        );
    }

    public function test_a_diagram_node_links_to_its_module_page_only_when_the_page_exists(): void
    {
        $repository = Repository::factory()->ready()->create();
        $documented = Module::factory()->for($repository)->create(['path' => 'app/Http']);
        Module::factory()->for($repository)->create(['path' => 'app/Small']);
        Document::factory()->forModule($documented)->create();
        Diagram::factory()->for($repository)->create(['meta' => [
            'title' => 'Layers',
            'nodes' => ['m1' => 'app/Http', 'm2' => 'app/Small'],
        ]]);

        $response = $this->get("/repositories/{$repository->id}/diagrams");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('diagrams.layers.modules', ['m1' => ['path' => 'app/Http', 'slug' => 'app-Http']])
        );
    }

    public function test_the_findings_the_evidence_with_github_links_and_the_interpretation_reach_the_page(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'shop']);
        $sha = $repository->last_sha;
        Diagram::factory()->for($repository)->create(['source_sha' => $sha, 'meta' => [
            'title' => 'Layers',
            'findings' => ['core' => [['module' => 'app/Models', 'modules' => 2, 'imports' => 4]]],
            'evidence' => [['from' => 'm1', 'to' => 'm2', 'from_label' => 'app/Http', 'to_label' => 'app/Models', 'imports' => 3, 'nodes' => ['m1', 'm2'], 'examples' => [['from' => 'app/Http/A b.php', 'to' => 'app/Models/M.php']]]],
            'interpretation' => ['source' => 'ai', 'reading' => 'It reads.', 'worth' => 'Worth it.', 'model' => 'claude-opus-5-5', 'input_hash' => 'secret'],
        ]]);

        $this->get("/repositories/{$repository->id}/diagrams")->assertInertia(fn (Assert $page) => $page
            ->where('diagrams.layers.findings.core.0.module', 'app/Models')
            ->where('diagrams.layers.evidence.0.imports', 3)
            ->where('diagrams.layers.evidence.0.examples.0.url', "https://github.com/acme/shop/blob/{$sha}/app/Http/A%20b.php")
            ->where('diagrams.layers.evidence.0.examples.0.target_url', "https://github.com/acme/shop/blob/{$sha}/app/Models/M.php")
            ->where('diagrams.layers.interpretation', ['source' => 'ai', 'reading' => 'It reads.', 'worth' => 'Worth it.', 'model' => 'claude-opus-5-5'])
        );
    }

    public function test_a_diagram_built_before_the_findings_existed_has_none(): void
    {
        $repository = Repository::factory()->ready()->create();
        Diagram::factory()->for($repository)->create(['meta' => ['title' => 'Layers']]);

        $this->get("/repositories/{$repository->id}/diagrams")->assertInertia(fn (Assert $page) => $page
            ->where('diagrams.layers.findings', null)
            ->where('diagrams.layers.evidence', [])
            ->where('diagrams.layers.interpretation', null)
        );
    }

    public function test_the_big_picture_comes_with_its_parts_and_its_numbered_steps_linked_to_the_code(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'shop', 'last_sha' => str_repeat('a', 40)]);
        $module = Module::factory()->for($repository)->create(['path' => 'app/Http']);
        Document::factory()->for($repository)->create(['module_id' => $module->id, 'slug' => 'app-http', 'title' => 'app/Http']);
        Diagram::factory()->for($repository)->create([
            'type' => DiagramType::Overview,
            'key' => '',
            'mermaid' => "flowchart TB\n  n_web(\"Web app\") -->|\"1 · starts a job\"| n_jobs[\"Background jobs\"]\n",
            'source_sha' => str_repeat('a', 40),
            'meta' => [
                'title' => 'How shop works',
                'reading' => 'Start at the web app.',
                'source' => 'ai',
                'stats' => ['proposed_edges' => 2, 'dropped_edges' => 1],
                'legend' => ['interface', 'main'],
                'parts' => [['id' => 'n_web', 'label' => 'Web app', 'does' => 'Takes the requests', 'kind' => 'interface', 'modules' => ['app/Http'], 'outside' => []]],
                'nodes' => ['n_web' => 'app/Http'],
                'evidence' => [[
                    'from' => 'n_web', 'to' => 'n_jobs', 'from_label' => 'Web app', 'to_label' => 'Background jobs', 'verb' => 'starts a job', 'step' => 1, 'imports' => 2, 'kinds' => ['dispatch' => 2],
                    'examples' => [['from' => 'app/Http/Controllers/RepositoryController.php', 'to' => 'app/Jobs/ProcessRepository.php', 'line' => 42, 'excerpt' => 'ProcessRepository::dispatch($repository);']],
                    'nodes' => ['n_web', 'n_jobs'],
                ]],
            ],
        ]);

        $this->get("/repositories/{$repository->id}/diagrams")->assertInertia(fn (Assert $page) => $page
            ->where('diagrams.overview.reading', 'Start at the web app.')
            ->where('diagrams.overview.source', 'ai')
            ->where('diagrams.overview.dropped', 1)
            ->where('diagrams.overview.parts.0.modules', [['path' => 'app/Http', 'slug' => 'app-http']])
            ->where('diagrams.overview.steps.0', [
                'from' => 'n_web', 'to' => 'n_jobs', 'from_label' => 'Web app', 'to_label' => 'Background jobs', 'text' => 'starts a job', 'step' => 1,
                'code' => [['path' => 'app/Http/Controllers/RepositoryController.php', 'line' => 42, 'excerpt' => 'ProcessRepository::dispatch($repository);', 'url' => 'https://github.com/acme/shop/blob/'.str_repeat('a', 40).'/app/Http/Controllers/RepositoryController.php#L42']],
            ])
            ->where('diagrams.overview.evidence.0.examples.0.url', 'https://github.com/acme/shop/blob/'.str_repeat('a', 40).'/app/Http/Controllers/RepositoryController.php#L42'),
        );
    }

    public function test_a_repository_without_diagrams_gets_an_empty_set(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}/diagrams");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('diagrams', ['overview' => null, 'flows' => [], 'layers' => null, 'container' => null, 'component' => null, 'context' => null])
        );
    }

    public function test_the_overview_page_previews_the_layers_view(): void
    {
        $repository = Repository::factory()->ready()->create();
        Diagram::factory()->for($repository)->create(['type' => DiagramType::Component]);
        Diagram::factory()->for($repository)->create();

        $response = $this->get("/repositories/{$repository->id}");

        // The Overview's "Explore" card draws its thumbnail from the workspace's architecture element.
        $response->assertInertia(fn (Assert $page) => $page
            ->where('workspace.elements.architecture.type', 'layers')
            ->where('workspace.elements.architecture.href', "/repositories/{$repository->id}/diagrams#straturi")
            ->where('showcase.diagram_count', 2)
        );
    }

    public function test_the_diagrams_of_another_repository_are_not_shown(): void
    {
        $repository = Repository::factory()->ready()->create();
        Diagram::factory()->create();

        $response = $this->get("/repositories/{$repository->id}/diagrams");

        $response->assertInertia(fn (Assert $page) => $page->where('diagrams.layers', null));
    }
}
