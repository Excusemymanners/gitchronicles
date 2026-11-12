<?php

namespace Tests\Feature\Console;

use App\Ai\TextGenerator;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSmallProject;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * php artisan repositories:diagrams: rebuilds the architecture diagrams with their meaning (the generator is faked).
 */
class RefreshRepositoryDiagramsTest extends TestCase
{
    use BuildsSmallProject;
    use RefreshDatabase;

    private function repository(): Repository
    {
        $repository = Repository::factory()->ready()->analyzed()->create(['owner' => 'acme', 'name' => 'shop']);
        $this->smallProject($repository);

        return $repository;
    }

    private function answer(): string
    {
        return json_encode(['diagrams' => [
            'layers' => ['reading' => '`routes` leads to `app/Http`.', 'worth' => 'The core is `app/Models`.'],
            'component' => ['reading' => '`app/Http` coordinates.', 'worth' => 'It has 2 commits.'],
        ]]);
    }

    public function test_rebuilds_the_diagrams_and_says_where_the_interpretation_came_from(): void
    {
        $repository = $this->repository();
        $this->app->instance(TextGenerator::class, $fake = new FakeTextGenerator([$this->answer(), 'not flows']));

        $this->artisan('repositories:diagrams', ['repository' => $repository->id])
            ->expectsOutput("#{$repository->id} acme/shop: rebuilt layers, component; interpretation: written by Claude.")
            ->assertSuccessful();
        $this->artisan('repositories:diagrams', ['repository' => $repository->id])
            ->expectsOutput("#{$repository->id} acme/shop: rebuilt layers, component; interpretation: from the cache, nothing sent to Claude.")
            ->assertSuccessful();

        $this->assertSame('ai', $repository->diagrams()->firstWhere('type', 'layers')->meta['interpretation']['source']);
    }

    public function test_without_ai_it_uses_the_fallback_and_all_refreshes_every_analyzed_repository(): void
    {
        $first = $this->repository();
        $second = Repository::factory()->ready()->analyzed()->create(['owner' => 'acme', 'name' => 'blog']);
        $this->smallProject($second);
        Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'raw']);

        $this->artisan('repositories:diagrams', ['--all' => true])
            ->expectsOutput("#{$first->id} acme/shop: rebuilt layers, component; interpretation: composed from the findings, without AI.")
            ->expectsOutput("#{$second->id} acme/blog: rebuilt layers, component; interpretation: composed from the findings, without AI.")
            ->assertSuccessful();
    }

    public function test_needs_a_repository_or_all(): void
    {
        $this->artisan('repositories:diagrams')->expectsOutput('Give the id of a repository, or --all.')->assertFailed();
        $this->artisan('repositories:diagrams', ['repository' => 999])->expectsOutput('There is no such repository.')->assertFailed();
    }
}
