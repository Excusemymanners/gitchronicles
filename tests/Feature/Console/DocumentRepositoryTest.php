<?php

namespace Tests\Feature\Console;

use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAnalyzedRepositories;
use Tests\TestCase;

/**
 * php artisan repositories:document: writes the documentation right away, without the queue (AI is off in tests).
 */
class DocumentRepositoryTest extends TestCase
{
    use CreatesAnalyzedRepositories;
    use RefreshDatabase;

    public function test_writes_every_page_then_skips_the_unchanged_ones(): void
    {
        ['repository' => $repository] = $this->shopRepository();

        $this->artisan('repositories:document', ['repository' => $repository->id])
            ->expectsOutput('  src-models: written from the code analysis')
            ->expectsOutput("#{$repository->id} acme/magazin: 5 of 5 pages written; 0 input tokens, 0 output tokens.")
            ->assertSuccessful();
        $this->artisan('repositories:document', ['repository' => $repository->id])
            ->expectsOutput('  src-models: unchanged')
            ->expectsOutput("#{$repository->id} acme/magazin: 0 of 5 pages written; 0 input tokens, 0 output tokens.")
            ->assertSuccessful();

        $this->assertSame(5, Document::whereBelongsTo($repository)->count());
        $this->assertNotNull($repository->fresh()->documented_at);
        $this->assertDatabaseCount('processing_runs', 0);
    }

    public function test_force_writes_unchanged_pages_and_page_writes_only_the_given_ones(): void
    {
        ['repository' => $repository] = $this->shopRepository();
        $this->artisan('repositories:document', ['repository' => $repository->id])->assertSuccessful();
        $repository->update(['documented_at' => null]);

        $this->artisan('repositories:document', ['repository' => $repository->id, '--force' => true, '--page' => ['overview', 'src-http']])
            ->expectsOutput("#{$repository->id} acme/magazin: 2 of 2 pages written; 0 input tokens, 0 output tokens.")
            ->assertSuccessful();

        $this->assertNull($repository->fresh()->documented_at);
        $this->assertSame(5, Document::whereBelongsTo($repository)->count());
    }

    public function test_rejects_a_page_the_repository_does_not_have(): void
    {
        ['repository' => $repository] = $this->shopRepository();

        $this->artisan('repositories:document', ['repository' => $repository->id, '--page' => ['nu-exista']])
            ->expectsOutput('Pagini necunoscute: nu-exista. Paginile acestui repository: radacina, src-http, src-models, overview, readme')
            ->assertFailed();
    }

    public function test_refuses_a_repository_that_was_not_analyzed_or_is_being_processed(): void
    {
        $notAnalyzed = Repository::factory()->ready()->create();
        ['repository' => $busy] = $this->shopRepository();
        ProcessingRun::factory()->for($busy)->create();

        $this->artisan('repositories:document', ['repository' => $notAnalyzed->id])
            ->expectsOutput("#{$notAnalyzed->id} {$notAnalyzed->owner}/{$notAnalyzed->name} has no code analysis yet. Start the processing first: php artisan repositories:process {$notAnalyzed->id}")
            ->assertFailed();
        $this->artisan('repositories:document', ['repository' => $busy->id])
            ->expectsOutput("#{$busy->id} acme/magazin is being processed right now, leaving it alone.")
            ->assertFailed();
        $this->artisan('repositories:document', ['repository' => 999])
            ->expectsOutput('There is no repository with id 999.')
            ->assertFailed();
        $this->assertDatabaseCount('documents', 0);
    }
}
