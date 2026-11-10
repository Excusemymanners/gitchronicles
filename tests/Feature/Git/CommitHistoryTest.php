<?php

namespace Tests\Feature\Git;

use App\Git\CommitHistory;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * CommitHistory with real git on small local repositories: the parsing of `git log`
 * (subjects with the delimiter bytes, Romanian letters and emoji; bodies that are never read),
 * merge commits, the limit and the "since" commit, and blobless clones like the ones
 * CloneRepository makes, with and without a reachable origin.
 */
class CommitHistoryTest extends TestCase
{
    use RunsGit;

    /**
     * A subject with the bytes CommitHistory uses as delimiters (\x1e, \x1f), shell and CSV
     * separators, Romanian letters and an emoji.
     */
    private const TRICKY_SUBJECT = "Adaugă «căutarea» | în \x1e listă; \x1f și \"ghilimele\" 🚀";

    public function test_reads_each_commit_with_its_author_date_subject_and_changed_lines(): void
    {
        $path = $this->newRepository();
        $this->writeFile('README.md', "# Titlu\nPrimul rând\n");
        $this->commit($path, ['Primul commit'], '2026-01-15T10:00:00+02:00', 'Ana Popescu');
        $this->writeFile('README.md', "# Titlu\nAl doilea rând\n");
        $this->writeFile('docs/notă cu spațiu.md', "a\nb\nc\n");
        $this->writeFile('logo.png', "\x89PNG\x00\x01\x02");
        $this->commit($path, [self::TRICKY_SUBJECT, "Corpul mesajului\n\x1enu se citește\nnici pe al doilea rând"], '2026-02-01T09:30:00+00:00', 'Ștefan Țugui');

        $commits = app(CommitHistory::class)->read($path, 500);

        $this->assertCount(2, $commits);
        [$newest, $oldest] = $commits;
        $this->assertSame($this->git($path, ['rev-parse', 'HEAD']), $newest['sha']);
        $this->assertSame($oldest['sha'], $newest['parent_sha']);
        $this->assertFalse($newest['is_merge']);
        $this->assertSame('Ștefan Țugui', $newest['author_name']);
        $this->assertSame('2026-02-01T09:30:00+00:00', $newest['authored_at']->toIso8601String());
        $this->assertSame(self::TRICKY_SUBJECT, $newest['subject']);
        $this->assertSame([
            ['path' => 'README.md', 'insertions' => 1, 'deletions' => 1],
            ['path' => 'docs/notă cu spațiu.md', 'insertions' => 3, 'deletions' => 0],
            ['path' => 'logo.png', 'insertions' => null, 'deletions' => null],
        ], $newest['files']);
        $this->assertSame([3, 4, 1], [$newest['files_changed'], $newest['insertions'], $newest['deletions']]);

        $this->assertNull($oldest['parent_sha']);
        $this->assertSame('Primul commit', $oldest['subject']);
        $this->assertSame('2026-01-15T08:00:00+00:00', $oldest['authored_at']->toIso8601String());
        $this->assertSame([1, 2, 0], [$oldest['files_changed'], $oldest['insertions'], $oldest['deletions']]);
    }

    public function test_marks_a_merge_commit_and_gives_it_no_files_and_no_counters(): void
    {
        $path = $this->newRepository();
        $this->writeFile('a.txt', "a\n");
        $this->commit($path, ['Primul commit']);
        $this->git($path, ['checkout', '--quiet', '-b', 'ramura']);
        $this->writeFile('b.txt', "b\n");
        $this->commit($path, ['Pe ramură']);
        $this->git($path, ['checkout', '--quiet', 'main']);
        $this->writeFile('c.txt', "c\n");
        $this->commit($path, ['Pe main']);
        $this->git($path, ['merge', '--quiet', '--no-edit', 'ramura']);

        $merge = app(CommitHistory::class)->read($path, 500)[0];

        $this->assertTrue($merge['is_merge']);
        $this->assertSame("Merge branch 'ramura'", $merge['subject']);
        $this->assertSame($this->git($path, ['rev-parse', 'HEAD^1']), $merge['parent_sha']);
        $this->assertSame([], $merge['files']);
        $this->assertSame([null, null, null], [$merge['files_changed'], $merge['insertions'], $merge['deletions']]);
    }

    public function test_reads_only_the_newest_commits_up_to_the_limit(): void
    {
        $path = $this->repositoryWithCommits(['Unu', 'Doi', 'Trei']);

        $commits = app(CommitHistory::class)->read($path, 2);

        $this->assertSame(['Trei', 'Doi'], array_column($commits, 'subject'));
    }

    public function test_reads_only_the_commits_after_the_given_one(): void
    {
        $path = $this->repositoryWithCommits(['Unu', 'Doi', 'Trei']);
        $firstSha = $this->git($path, ['rev-parse', 'HEAD~2']);

        $commits = app(CommitHistory::class)->read($path, 500, since: $firstSha);

        $this->assertSame(['Trei', 'Doi'], array_column($commits, 'subject'));
    }

    public function test_reads_the_newest_commits_when_the_clone_does_not_have_the_given_one(): void
    {
        $path = $this->repositoryWithCommits(['Unu', 'Doi']);

        $commits = app(CommitHistory::class)->read($path, 500, since: str_repeat('a', 40));

        $this->assertSame(['Doi', 'Unu'], array_column($commits, 'subject'));
    }

    public function test_downloads_the_old_file_contents_of_a_blobless_clone_to_count_the_lines(): void
    {
        $this->repositoryWithCommits(['Unu', 'Doi', 'Trei']);
        $clonePath = $this->bloblessClone();

        $commits = app(CommitHistory::class)->read($clonePath, 500);

        $this->assertSame([[1, 1, 0], [1, 1, 0], [1, 1, 0]], array_map(
            fn (array $commit): array => [$commit['files_changed'], $commit['insertions'], $commit['deletions']],
            $commits,
        ));
    }

    public function test_imports_the_commits_without_line_counts_when_the_file_contents_cannot_be_downloaded(): void
    {
        $this->repositoryWithCommits(['Unu', 'Doi']);
        $clonePath = $this->bloblessClone();
        Storage::disk('local')->deleteDirectory('repo');

        $commits = app(CommitHistory::class)->read($clonePath, 500);

        $this->assertSame(['Doi', 'Unu'], array_column($commits, 'subject'));
        $this->assertSame([['path' => 'jurnal.txt', 'insertions' => null, 'deletions' => null]], $commits[0]['files']);
        $this->assertSame([1, null, null], [$commits[0]['files_changed'], $commits[0]['insertions'], $commits[0]['deletions']]);
    }

    /**
     * An empty git repository on "main" in the fake "local" disk, at "repo".
     */
    private function newRepository(): string
    {
        Storage::disk('local')->makeDirectory('repo');
        $path = Storage::disk('local')->path('repo');
        $this->git($path, ['init', '--quiet', '-b', 'main']);

        return $path;
    }

    /**
     * A repository with one commit per subject (oldest first), each adding the line "{subject}"
     * to the same file, jurnal.txt: in a blobless clone only its newest version is present.
     *
     * @param  list<string>  $subjects
     */
    private function repositoryWithCommits(array $subjects): string
    {
        $path = $this->newRepository();
        $journal = '';

        foreach ($subjects as $subject) {
            $journal .= "{$subject}\n";
            $this->writeFile('jurnal.txt', $journal);
            $this->commit($path, [$subject]);
        }

        return $path;
    }

    /**
     * A blobless clone of "repo" (git clone --filter=blob:none, like CloneRepository), at "clone".
     * The file:// URL makes git use the network protocol, so the filter is honored.
     */
    private function bloblessClone(): string
    {
        $originPath = Storage::disk('local')->path('repo');
        $this->git($originPath, ['config', 'uploadpack.allowFilter', 'true']);

        $this->git(Storage::disk('local')->path(''), ['clone', '--quiet', '--filter=blob:none', "file://{$originPath}", 'clone']);

        return Storage::disk('local')->path('clone');
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        Storage::disk('local')->put("repo/{$relativePath}", $contents);
    }

    /**
     * Commit everything in the repository with this message (one -m per paragraph: the first one
     * is the subject), author and author date.
     *
     * @param  list<string>  $paragraphs
     */
    private function commit(string $path, array $paragraphs, string $date = '2026-01-01T12:00:00+00:00', string $author = 'Ana Popescu'): void
    {
        $messages = array_merge(...array_map(fn (string $paragraph): array => ['-m', $paragraph], $paragraphs));

        $this->git($path, ['add', '--all']);
        $this->git($path, ['commit', '--quiet', "--author={$author} <autor@example.com>", "--date={$date}", ...$messages]);
    }
}
