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

    public function test_a_repository_without_diagrams_gets_an_empty_set(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}/diagrams");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('diagrams', ['flows' => [], 'layers' => null, 'container' => null, 'component' => null, 'context' => null])
        );
    }

    public function test_the_overview_page_previews_the_layers_view(): void
    {
        $repository = Repository::factory()->ready()->create();
        Diagram::factory()->for($repository)->create(['type' => DiagramType::Component]);
        Diagram::factory()->for($repository)->create();

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('diagram_preview.type', 'layers')
            ->where('diagram_preview.title', 'Arhitectura pe straturi')
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
