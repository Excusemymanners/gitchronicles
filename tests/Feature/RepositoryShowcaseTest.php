<?php

namespace Tests\Feature;

use App\Enums\MediaLanguage;
use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Models\Commit;
use App\Models\Diagram;
use App\Models\Document;
use App\Models\MediaAsset;
use App\Models\Module;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Overview is a showcase of what GitChronicle made for a repository: besides the props of every page it
 * gets the videos and slides (the same "media" prop as the „Video & slides” page) and "showcase", the facts
 * of the „Explore” cards and the README download (RepositoryShowcaseResource).
 */
class RepositoryShowcaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_repository_has_an_empty_showcase(): void
    {
        $repository = Repository::factory()->create();

        $this->get("/repositories/{$repository->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('repositories/show')
                ->where('showcase', [
                    'documentation' => null,
                    'readme' => null,
                    'diagram_count' => 0,
                    'last_change' => null,
                    'latest_commit' => null,
                ])
                ->where('media.videos', [])
                ->where('media.slides', null)
            );
    }

    public function test_the_showcase_counts_the_pages_and_diagrams_and_sizes_the_readme(): void
    {
        $repository = Repository::factory()->ready()->create();
        $module = Module::factory()->for($repository)->create(['path' => 'app/Http']);
        Document::factory()->for($repository)->overview()->create();
        $readme = Document::factory()->for($repository)->readme()->create(['markdown' => "# Shop\n\nA small shop, ăîș.\n"]);
        Document::factory()->forModule($module)->create();
        Diagram::factory()->for($repository)->create();
        Diagram::factory()->for($repository)->flow()->create();

        $this->get("/repositories/{$repository->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('showcase.documentation.page_count', 3)
                ->where('showcase.documentation.pages', [
                    ['title' => 'Overview', 'href' => "/repositories/{$repository->id}/docs"],
                    ['title' => 'Generated README', 'href' => "/repositories/{$repository->id}/docs/readme"],
                    ['title' => 'app/Http', 'href' => "/repositories/{$repository->id}/docs/app-Http"],
                ])
                ->where('showcase.documentation.href', "/repositories/{$repository->id}/docs")
                // The size of the file the download serves: the markdown's bytes, not its characters.
                ->where('showcase.readme', [
                    'download' => "/repositories/{$repository->id}/docs/readme.md",
                    'size_bytes' => strlen($readme->markdown),
                ])
                ->where('showcase.diagram_count', 2)
            );
    }

    public function test_the_showcase_says_what_the_newest_successful_update_changed(): void
    {
        $repository = Repository::factory()->ready()->create();
        $finishedAt = now()->subHour()->startOfSecond();

        // The first processing compared nothing, and a failed update changed nothing: neither is "the last change".
        ProcessingRun::factory()->for($repository)->create(['status' => ProcessingRunStatus::Succeeded, 'to_sha' => str_repeat('a', 40)]);
        ProcessingRun::factory()->for($repository)->create([
            'mode' => ProcessingMode::Incremental,
            'status' => ProcessingRunStatus::Succeeded,
            'from_sha' => str_repeat('a', 40),
            'to_sha' => str_repeat('b', 40),
            'finished_at' => $finishedAt,
            'files_changed' => 4,
            'modules_affected' => 2,
            'documents_regenerated' => 1,
        ]);
        ProcessingRun::factory()->for($repository)->create([
            'mode' => ProcessingMode::Incremental,
            'status' => ProcessingRunStatus::Failed,
            'from_sha' => str_repeat('b', 40),
            'to_sha' => str_repeat('c', 40),
        ]);

        $newest = Commit::factory()->for($repository)->create(['subject' => 'Add the cart', 'authored_at' => now()->subDay()->startOfSecond()]);
        Commit::factory()->for($repository)->create(['authored_at' => now()->subWeek()]);

        $this->get("/repositories/{$repository->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('showcase.last_change', [
                    'from_sha' => str_repeat('a', 40),
                    'to_sha' => str_repeat('b', 40),
                    'finished_at' => $finishedAt->toIso8601String(),
                    'files_changed' => 4,
                    'modules_affected' => 2,
                    'documents_regenerated' => 1,
                ])
                ->where('showcase.latest_commit', [
                    'sha' => $newest->sha,
                    'subject' => 'Add the cart',
                    'authored_at' => $newest->authored_at->toIso8601String(),
                ])
            );
    }

    public function test_the_overview_gets_the_video_of_every_language_with_its_player_files(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readyVideo()->create(['language' => MediaLanguage::En]);
        MediaAsset::factory()->for($repository)->readyVideo()->create(['language' => MediaLanguage::Ro]);

        $this->get("/repositories/{$repository->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('media.videos', 2)
                ->where('media.videos.0.language', 'en')
                ->where('media.videos.0.duration_ms', 31_200)
                ->where('media.videos.0.size_bytes', 1_048_576)
                ->where('media.videos.0.src', fn (string $src): bool => str_starts_with($src, "/repositories/{$repository->id}/media/video?lang=en"))
                ->where('media.videos.0.poster', fn (string $poster): bool => str_starts_with($poster, "/repositories/{$repository->id}/media/poster?lang=en"))
                ->where('media.videos.1.language', 'ro')
            );
    }

    public function test_the_named_languages_come_before_the_files_of_unknown_language(): void
    {
        // The facts row of the showcase draws the first "language_count" languages: they must be the named ones,
        // even when the files of unknown language have more lines.
        $repository = Repository::factory()->ready()->analyzed()->create();
        $module = Module::factory()->for($repository)->create(['path' => '/']);
        RepositoryFile::factory()->for($repository)->for($module)->create(['language' => null, 'lines' => 500]);
        RepositoryFile::factory()->for($repository)->for($module)->create(['language' => 'PHP', 'lines' => 100]);

        $this->get("/repositories/{$repository->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('code_structure.language_count', 1)
                ->where('code_structure.languages.0.name', 'PHP')
                ->where('code_structure.languages.1.name', 'Other')
            );
    }
}
