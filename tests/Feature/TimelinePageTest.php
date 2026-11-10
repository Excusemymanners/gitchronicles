<?php

namespace Tests\Feature;

use App\Enums\ProcessingRunStatus;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The „Istoric” tab (the commits, the runs placed on them, the „Zone fierbinți” and the
 * contributors) and the „Activitate” and „Zone fierbinți” sections of „Prezentare”.
 * Everything is public, so every request is made as a guest.
 */
class TimelinePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_history_with_the_runs_the_hotspots_and_the_contributors(): void
    {
        $repository = Repository::factory()->ready()->create();
        $older = Commit::factory()->for($repository)->create(['author_name' => 'Ana', 'authored_at' => '2026-10-01 10:00:00']);
        $newest = Commit::factory()->for($repository)->create([
            'author_name' => 'Ion', 'authored_at' => '2026-11-01 10:00:00', 'subject' => 'Adaugă căutarea',
            'files_changed' => 1, 'insertions' => 12, 'deletions' => 3,
        ]);
        CommitFile::factory()->for($older)->create(['path' => 'app/Search.php']);
        CommitFile::factory()->for($newest)->create(['path' => 'app/Search.php']);
        $run = ProcessingRun::factory()->for($repository)->create(['to_sha' => $newest->sha, 'documents_regenerated' => 4, 'input_tokens' => 1200]);
        $run->finish(ProcessingRunStatus::Succeeded);
        ProcessingRun::factory()->for($repository)->create(['to_sha' => null, 'status' => ProcessingRunStatus::Failed]);

        $response = $this->get("/repositories/{$repository->id}/timeline");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/timeline')
            ->where('summary', [
                'commit_count' => 2,
                'contributor_count' => 2,
                'first_commit_at' => '2026-10-01T10:00:00+00:00',
                'last_commit_at' => '2026-11-01T10:00:00+00:00',
            ])
            ->has('commits.data', 2)
            ->where('commits.data.0', [
                'id' => $newest->id,
                'sha' => $newest->sha,
                'is_merge' => false,
                'author_name' => 'Ion',
                'authored_at' => '2026-11-01T10:00:00+00:00',
                'subject' => 'Adaugă căutarea',
                'files_changed' => 1,
                'insertions' => 12,
                'deletions' => 3,
            ])
            ->has('runs', 1)
            ->where('runs.0.sha', $newest->sha)
            ->where('runs.0.status', 'succeeded')
            ->where('runs.0.mode', 'initial')
            ->where('runs.0.documents_regenerated', 4)
            ->where('runs.0.input_tokens', 1200)
            ->where('runs.0.cost_usd_equivalent', null)
            ->where('hotspots.0.path', 'app/Search.php')
            ->where('hotspots.0.commit_count', 2)
            ->where('contributors.0.name', 'Ana')
            ->has('contributors', 2)
            ->where('filters.q', '')
        );
    }

    public function test_commits_come_newest_first_thirty_per_page(): void
    {
        $repository = Repository::factory()->ready()->create();
        foreach (range(1, 35) as $day) {
            Commit::factory()->for($repository)->create(['subject' => "Commit {$day}", 'authored_at' => sprintf('2026-01-%02d 10:00:00', $day % 31 + 1)]);
        }

        $firstPage = $this->get("/repositories/{$repository->id}/timeline");
        $secondPage = $this->get("/repositories/{$repository->id}/timeline?page=2");

        $firstPage->assertInertia(fn (Assert $page) => $page->has('commits.data', 30));
        $this->assertSame(2, $firstPage->viewData('page')['scrollProps']['commits']['nextPage']);
        $secondPage->assertInertia(fn (Assert $page) => $page->has('commits.data', 5));
        $this->assertNull($secondPage->viewData('page')['scrollProps']['commits']['nextPage']);
        $shownDates = array_column([...$firstPage->inertiaProps('commits.data'), ...$secondPage->inertiaProps('commits.data')], 'authored_at');
        $this->assertSame(collect($shownDates)->sortDesc()->values()->all(), $shownDates);
    }

    public function test_search_finds_the_commits_by_subject_or_by_author(): void
    {
        $repository = Repository::factory()->ready()->create();
        Commit::factory()->for($repository)->create(['subject' => 'Repară căutarea', 'author_name' => 'Ana']);
        Commit::factory()->for($repository)->create(['subject' => 'Actualizează README', 'author_name' => 'Ion Căutaru']);
        Commit::factory()->for($repository)->create(['subject' => 'Adaugă teste', 'author_name' => 'Maria']);

        $response = $this->get("/repositories/{$repository->id}/timeline?q=".urlencode('căuta'));

        $response->assertInertia(fn (Assert $page) => $page->where('filters.q', 'căuta'));
        $found = array_column($response->inertiaProps('commits.data'), 'subject');
        $this->assertEqualsCanonicalizing(['Repară căutarea', 'Actualizează README'], $found);
    }

    public function test_a_search_longer_than_100_characters_is_rejected(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}/timeline?q=".str_repeat('a', 101));

        $response->assertSessionHasErrors('q');
    }

    public function test_the_history_is_empty_until_the_repository_is_cloned_and_imported(): void
    {
        $repository = Repository::factory()->create();

        $response = $this->get("/repositories/{$repository->id}/timeline");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary', null)
            ->has('commits.data', 0)
            ->where('runs', [])
            ->where('hotspots', [])
            ->where('contributors', [])
        );
    }

    public function test_the_overview_shows_the_activity_and_the_five_hottest_files_once_the_history_is_imported(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $repository = Repository::factory()->ready()->create();
        foreach (['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php'] as $path) {
            CommitFile::factory()
                ->for(Commit::factory()->for($repository)->state(['authored_at' => '2026-11-02 10:00:00', 'parent_sha' => null]))
                ->create(['path' => $path]);
        }

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/show')
            ->has('activity.months', 12)
            ->where('activity.months.11', ['month' => '2026-11', 'commit_count' => 6])
            ->where('activity.commits_last_year', 6)
            ->where('activity.contributor_count', 6)
            ->has('hotspots', 5)
        );
    }
}
