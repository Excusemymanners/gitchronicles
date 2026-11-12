<?php

namespace Tests\Feature;

use App\Enums\ProcessingRunStatus;
use App\Models\CodeChunk;
use App\Models\Commit;
use App\Models\Diagram;
use App\Models\Document;
use App\Models\MediaAsset;
use App\Models\Module;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_rendered_with_inertia(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('repositories')
        );
    }

    public function test_home_page_lists_the_repositories_of_every_user_newest_first(): void
    {
        $ana = User::factory()->create();
        $ion = User::factory()->create();
        $this->travelTo('2026-11-01 10:00:00');
        $older = Repository::factory()->for($ana)->create();
        $this->travelTo('2026-11-02 10:00:00');
        $newer = Repository::factory()->for($ion)->create();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('repositories', 2)
            ->where('repositories.0.id', $newer->id)
            ->where('repositories.1.id', $older->id)
        );
    }

    public function test_home_page_sends_each_repository_as_a_card_without_its_user(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $repository = Repository::factory()->create([
            'url' => 'https://github.com/laravel/framework',
            'owner' => 'laravel',
            'name' => 'framework',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('repositories', [[
                'id' => $repository->id,
                'url' => 'https://github.com/laravel/framework',
                'owner' => 'laravel',
                'name' => 'framework',
                'full_name' => 'laravel/framework',
                'description' => null,
                'status' => 'pending',
                'status_label' => 'Queued',
                'is_processing' => true,
                'default_branch' => null,
                'last_sha' => null,
                'error' => null,
                'cloned_at' => null,
                'analyzed_at' => null,
                'documented_at' => null,
                'created_at' => '2026-11-09T10:00:00+00:00',
                'facts' => [
                    'file_count' => 0,
                    'line_count' => 0,
                    'module_count' => 0,
                    'commit_count' => 0,
                    'document_count' => 0,
                    'diagram_count' => 0,
                    'has_assistant' => false,
                    'has_video' => false,
                    'languages' => [],
                    'processed_at' => null,
                ],
            ]])
        );
    }

    public function test_a_repository_card_carries_real_facts_about_what_was_processed(): void
    {
        $repository = Repository::factory()->ready()->create();
        RepositoryFile::factory()->for($repository)->create(['language' => 'PHP', 'lines' => 100]);
        RepositoryFile::factory()->for($repository)->create(['language' => 'PHP', 'lines' => 50]);
        RepositoryFile::factory()->for($repository)->create(['language' => 'TypeScript', 'lines' => 30]);
        RepositoryFile::factory()->for($repository)->create(['language' => null, 'lines' => 20]);
        Module::factory()->count(2)->for($repository)->create();
        Commit::factory()->count(3)->for($repository)->create();
        Document::factory()->count(2)->for($repository)->create();
        Diagram::factory()->for($repository)->create();
        ProcessingRun::factory()->for($repository)->create([
            'status' => ProcessingRunStatus::Succeeded,
            'finished_at' => '2026-11-10 12:00:00',
        ]);
        ProcessingRun::factory()->for($repository)->create([
            'status' => ProcessingRunStatus::Failed,
            'finished_at' => '2026-11-10 13:00:00',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('repositories.0.id', $repository->id)
            ->where('repositories.0.facts', [
                'file_count' => 4,
                'line_count' => 200,
                'module_count' => 2,
                'commit_count' => 3,
                'document_count' => 2,
                'diagram_count' => 1,
                'has_assistant' => false,
                'has_video' => false,
                // The share of all the lines, the biggest language first; files of an unknown language are not named.
                'languages' => [
                    ['name' => 'PHP', 'share' => 0.75],
                    ['name' => 'TypeScript', 'share' => 0.15],
                ],
                // The last successful run, not the failed one after it.
                'processed_at' => '2026-11-10T12:00:00+00:00',
            ])
        );
    }

    public function test_a_repository_card_names_only_the_three_biggest_languages(): void
    {
        $repository = Repository::factory()->ready()->create();

        foreach (['PHP' => 40, 'TypeScript' => 30, 'CSS' => 20, 'Blade' => 10] as $language => $lines) {
            RepositoryFile::factory()->for($repository)->create(['language' => $language, 'lines' => $lines]);
        }

        $response = $this->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('repositories.0.facts.languages', [
                ['name' => 'PHP', 'share' => 0.4],
                ['name' => 'TypeScript', 'share' => 0.3],
                ['name' => 'CSS', 'share' => 0.2],
            ])
        );
    }

    public function test_a_repository_card_says_which_chapters_exist(): void
    {
        $withChapters = Repository::factory()->ready()->create();
        $file = RepositoryFile::factory()->for($withChapters)->create();
        CodeChunk::factory()->create(['repository_file_id' => $file->id]);
        MediaAsset::factory()->readyVideo()->for($withChapters)->create();
        $onlyAnalyzed = Repository::factory()->ready()->create();
        // A video that is still being made does not count as a chapter.
        MediaAsset::factory()->for($onlyAnalyzed)->create();

        $response = $this->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('repositories.0.id', $onlyAnalyzed->id)
            ->where('repositories.0.facts.has_assistant', false)
            ->where('repositories.0.facts.has_video', false)
            ->where('repositories.1.id', $withChapters->id)
            ->where('repositories.1.facts.has_assistant', true)
            ->where('repositories.1.facts.has_video', true)
        );
    }

    public function test_home_page_runs_the_same_number_of_queries_for_one_repository_and_for_many(): void
    {
        foreach (range(1, 4) as $number) {
            $repository = Repository::factory()->ready()->create();
            RepositoryFile::factory()->count(2)->for($repository)->create();
            Module::factory()->for($repository)->create();
            Commit::factory()->for($repository)->create();
        }

        // One query for the repositories with their aggregates, one for the languages of all of them.
        $this->expectsDatabaseQueryCount(2);

        $this->get('/')->assertOk();
    }
}
