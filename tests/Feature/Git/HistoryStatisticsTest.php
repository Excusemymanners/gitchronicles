<?php

namespace Tests\Feature\Git;

use App\Git\HistoryStatistics;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The numbers the pages show about the imported history: the „Zone fierbinți” (ranking and
 * the files left out), the contributors and the commits of the last 12 months.
 */
class HistoryStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hotspots_rank_the_files_by_the_number_of_commits_that_changed_them(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitChanging($repository, ['app/Order.php' => [10, 2], 'app/User.php' => [5, 0]], 'Ana', '2026-03-01 10:00:00');
        $this->commitChanging($repository, ['app/Order.php' => [3, 1], 'app/User.php' => [1, 1]], 'Ion', '2026-03-02 10:00:00');
        $this->commitChanging($repository, ['app/Order.php' => [2, 0], 'README.md' => [4, 0]], 'Ana', '2026-03-03 10:00:00');
        RepositoryFile::factory()->for($repository)->create(['path' => 'app/Order.php', 'lines' => 120]);

        $hotspots = app(HistoryStatistics::class)->hotspots($repository, 10);

        $this->assertSame(['app/Order.php', 'app/User.php', 'README.md'], array_column($hotspots, 'path'));
        $this->assertSame([
            'path' => 'app/Order.php',
            'commit_count' => 3,
            'insertions' => 15,
            'deletions' => 3,
            'last_changed_at' => '2026-03-03T10:00:00+00:00',
            'author_count' => 2,
            'lines' => 120,
        ], $hotspots[0]);
        $this->assertNull($hotspots[1]['lines']);
    }

    public function test_hotspot_line_totals_are_unknown_when_git_could_not_count_one_of_the_changes(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitChanging($repository, ['public/favicon.ico' => [0, 0]], 'Ana', '2026-03-01 10:00:00');
        $this->commitChanging($repository, ['public/favicon.ico' => [null, null]], 'Ana', '2026-03-02 10:00:00');

        $hotspot = app(HistoryStatistics::class)->hotspots($repository, 10)[0];

        $this->assertSame([2, null, null], [$hotspot['commit_count'], $hotspot['insertions'], $hotspot['deletions']]);
    }

    public function test_hotspots_with_the_same_number_of_commits_show_the_most_recently_changed_first(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitChanging($repository, ['old.php' => [1, 0]], 'Ana', '2026-01-01 10:00:00');
        $this->commitChanging($repository, ['new.php' => [1, 0]], 'Ana', '2026-02-01 10:00:00');

        $hotspots = app(HistoryStatistics::class)->hotspots($repository, 10);

        $this->assertSame(['new.php', 'old.php'], array_column($hotspots, 'path'));
    }

    public function test_hotspots_leave_out_lock_files_libraries_build_output_and_generated_files(): void
    {
        $repository = Repository::factory()->ready()->create();
        $ignoredFiles = [
            'composer.lock' => [1, 1], 'frontend/package-lock.json' => [1, 1], 'vendor/laravel/Kernel.php' => [1, 1],
            'web/node_modules/react/index.js' => [1, 1], 'dist/app.js' => [1, 1], 'public/build/assets/app.js' => [1, 1],
            'public/js/app.min.js' => [1, 1], 'public/css/app.min.css' => [1, 1], 'public/js/app.js.map' => [1, 1],
        ];
        $this->commitChanging($repository, $ignoredFiles, 'Ana', '2026-03-01 10:00:00');
        $this->commitChanging($repository, $ignoredFiles, 'Ana', '2026-03-02 10:00:00');
        $this->commitChanging($repository, ['src/app.js' => [1, 0], 'docs/building.md' => [1, 0]], 'Ana', '2026-03-03 10:00:00');

        $hotspots = app(HistoryStatistics::class)->hotspots($repository, 10);

        $this->assertSame(['docs/building.md', 'src/app.js'], array_column($hotspots, 'path'));
    }

    public function test_hotspots_count_only_the_commits_of_the_same_repository_and_keep_the_limit(): void
    {
        $repository = Repository::factory()->ready()->create();
        $otherRepository = Repository::factory()->ready()->create();
        $this->commitChanging($repository, ['a.php' => [1, 0], 'b.php' => [1, 0], 'c.php' => [1, 0]], 'Ana', '2026-03-01 10:00:00');
        $this->commitChanging($otherRepository, ['c.php' => [1, 0]], 'Ana', '2026-03-02 10:00:00');
        $this->commitChanging($otherRepository, ['c.php' => [1, 0]], 'Ana', '2026-03-03 10:00:00');

        $hotspots = app(HistoryStatistics::class)->hotspots($repository, 2);

        $this->assertSame([['a.php', 1], ['b.php', 1]], array_map(fn (array $hotspot): array => [$hotspot['path'], $hotspot['commit_count']], $hotspots));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'a lock file in a subfolder' => ['web/yarn.lock', true],
            'a library folder at any depth' => ['packages/ui/node_modules/x/index.js', true],
            'a minified file' => ['assets/vendor.min.js', true],
            'a file named like a lock file' => ['docs/composer.lock.md', false],
            'a folder whose name only starts like build' => ['builder/main.go', false],
            'a file named build' => ['scripts/build', false],
        ];
    }

    #[DataProvider('paths')]
    public function test_decides_which_paths_are_left_out_of_the_hotspots(string $path, bool $isIgnored): void
    {
        $this->assertSame($isIgnored, HistoryStatistics::isIgnored($path));
    }

    public function test_contributors_are_ranked_by_their_number_of_commits_with_their_first_and_last_commit(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ion', '2026-01-05 10:00:00');
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ana', '2026-02-01 10:00:00');
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ana', '2026-04-01 10:00:00');

        $contributors = app(HistoryStatistics::class)->contributors($repository, 10);

        $this->assertSame([
            ['name' => 'Ana', 'commit_count' => 2, 'first_commit_at' => '2026-02-01T10:00:00+00:00', 'last_commit_at' => '2026-04-01T10:00:00+00:00'],
            ['name' => 'Ion', 'commit_count' => 1, 'first_commit_at' => '2026-01-05T10:00:00+00:00', 'last_commit_at' => '2026-01-05T10:00:00+00:00'],
        ], $contributors);
    }

    public function test_activity_counts_the_commits_of_each_of_the_last_twelve_months(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $repository = Repository::factory()->ready()->create();
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ana', '2025-11-30 23:00:00');
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ana', '2025-12-01 00:00:00');
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ion', '2026-11-09 08:00:00');
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ion', '2026-11-10 09:00:00');

        $activity = app(HistoryStatistics::class)->activity($repository);

        $this->assertSame(['2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_column($activity['months'], 'month'));
        $this->assertSame([1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2], array_column($activity['months'], 'commit_count'));
        $this->assertSame(3, $activity['commits_last_year']);
        $this->assertSame(4, $activity['commit_count']);
        $this->assertSame(2, $activity['contributor_count']);
        $this->assertSame('2026-11-10T09:00:00+00:00', $activity['last_commit_at']);
    }

    public function test_activity_does_not_count_the_months_older_than_the_imported_commits_as_empty(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $repository = Repository::factory()->ready()->create();
        // Only the newest commits were imported: the oldest one still has a parent.
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ana', '2026-09-20 10:00:00');
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ana', '2026-10-05 10:00:00');

        $activity = app(HistoryStatistics::class)->activity($repository);

        $this->assertSame([null, null, null, null, null, null, null, null, null, null, 1, 0], array_column($activity['months'], 'commit_count'));
        $this->assertNull($activity['commits_last_year']);
        $this->assertFalse($activity['is_complete']);
        $this->assertSame('2026-09-20T10:00:00+00:00', $activity['first_commit_at']);
    }

    public function test_activity_counts_every_month_once_the_first_commit_of_the_project_is_imported(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $repository = Repository::factory()->ready()->create();
        $this->commitChanging($repository, ['a.php' => [1, 0]], 'Ana', '2026-09-20 10:00:00')->update(['parent_sha' => null]);

        $activity = app(HistoryStatistics::class)->activity($repository);

        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0], array_column($activity['months'], 'commit_count'));
        $this->assertSame(1, $activity['commits_last_year']);
        $this->assertTrue($activity['is_complete']);
    }

    public function test_activity_and_summary_are_null_before_the_first_import(): void
    {
        $repository = Repository::factory()->ready()->create();

        $this->assertNull(app(HistoryStatistics::class)->activity($repository));
        $this->assertNull(app(HistoryStatistics::class)->summary($repository));
    }

    /**
     * A commit of the repository that changed these files: path => [lines added, lines removed].
     *
     * @param  array<string, array{int|null, int|null}>  $files
     */
    private function commitChanging(Repository $repository, array $files, string $author, string $authoredAt): Commit
    {
        $commit = Commit::factory()->for($repository)->create(['author_name' => $author, 'authored_at' => $authoredAt]);

        foreach ($files as $path => [$insertions, $deletions]) {
            CommitFile::factory()->for($commit)->create(['path' => $path, 'insertions' => $insertions, 'deletions' => $deletions]);
        }

        return $commit;
    }
}
