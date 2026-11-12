<?php

namespace App\Git;

use App\Exceptions\RepositoryProcessingException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads the commits of a cloned repository with `git log`: for each commit its sha, parents,
 * author, date, subject and the files it changed with the lines added and removed (--numstat).
 *
 * The clone is "blobless" (git clone --filter=blob:none, see CloneRepository): it has every commit
 * and folder listing, but the file contents only for the latest commit. Counting lines needs the
 * older contents too, and git would download them one commit at a time (measured: about 0.7 s per
 * commit, so 6 minutes for 500 commits). So read() works in three steps:
 *
 * 1. downloadFileContents(): `git backfill` downloads, in one request, the contents that the
 *    commits we are about to read need (measured: about 5 s for the newest 500 commits of a big repository);
 * 2. `git log --numstat` reads the history with GIT_NO_LAZY_FETCH=1, so it never goes to the
 *    network: if a content is still missing, it fails at once instead of waiting;
 * 3. if that failed (GitHub did not answer, or the git on the server is older than 2.49 and has no
 *    backfill), `git log --name-only` reads the same commits without line counts. It needs no file
 *    contents, so the commits and their files are always imported, only the +/− counts stay unknown.
 *
 * Every command is an array (no shell), has a timeout, and ends its revisions with "--",
 * so a file named like a revision ("HEAD") cannot be mistaken for one.
 */
final class CommitHistory
{
    /**
     * After this many seconds the download of the file contents is abandoned (the counts are skipped).
     */
    public const DOWNLOAD_TIMEOUT_SECONDS = 120;

    /**
     * After this many seconds `git log` is stopped. It only reads local files, so it is fast.
     */
    public const LOG_TIMEOUT_SECONDS = 60;

    /**
     * The first byte of every commit in the output (ASCII "record separator").
     *
     * The fields of a commit are separated by NUL, the one byte git never allows in a commit message,
     * an author name or a file path; with -z the changed files end with NUL too. So the output is cut
     * at every NUL, and a piece that starts with this byte starts a new commit: a file piece always
     * starts with a digit or "-" (--numstat) or follows the commit's own fields (--name-only).
     */
    private const COMMIT_MARKER = "\x1e";

    /**
     * What git prints for each commit: the marker, then sha, parents (separated by spaces),
     * author name (with the project's .mailmap, so one person with two spellings counts once),
     * author date (ISO 8601) and subject (the first line of the message; the body is never read).
     */
    private const FORMAT = '%x1e%H%x00%P%x00%aN%x00%aI%x00%s%x00';

    /**
     * The newest commits of the clone, newest first, at most $limit of them.
     * With $since (a commit that was already imported) only the commits after it are read;
     * a $since the clone no longer has (the history was rewritten) is ignored.
     *
     * @return list<array{sha: string, parent_sha: string|null, is_merge: bool, author_name: string, authored_at: CarbonImmutable, subject: string, files_changed: int|null, insertions: int|null, deletions: int|null, files: list<array{path: string, insertions: int|null, deletions: int|null}>}>
     */
    public function read(string $clonePath, int $limit, ?string $since = null): array
    {
        $revisions = $since !== null && $this->hasCommit($clonePath, $since) ? ['HEAD', "^{$since}"] : ['HEAD'];

        $this->downloadFileContents($clonePath, $revisions, $limit);

        $result = $this->log($clonePath, $revisions, $limit, '--numstat');
        $withLineCounts = $result->successful();

        if (! $withLineCounts) {
            Log::warning('git log --numstat failed: the commits are imported without line counts.', [
                'clone' => $clonePath,
                'git_output' => Str::limit($result->errorOutput(), 1000),
            ]);

            $result = $this->log($clonePath, $revisions, $limit, '--name-only');
        }

        if ($result->failed()) {
            Log::error('git log failed.', [
                'clone' => $clonePath,
                'exit_code' => $result->exitCode(),
                'git_output' => Str::limit($result->errorOutput(), 1000),
            ]);

            throw RepositoryProcessingException::because('history_failed');
        }

        return self::parse($result->output(), $withLineCounts);
    }

    /**
     * Turn the output of `git log -z --format=FORMAT --numstat` (or --name-only) into commits.
     * A merge commit has no files and no counters: git shows no changes for it.
     *
     * Text that is not valid UTF-8 (old repositories) is repaired, so it can be saved and sent as JSON,
     * and it is cut to the length of its database column.
     *
     * @return list<array{sha: string, parent_sha: string|null, is_merge: bool, author_name: string, authored_at: CarbonImmutable, subject: string, files_changed: int|null, insertions: int|null, deletions: int|null, files: list<array{path: string, insertions: int|null, deletions: int|null}>}>
     */
    private static function parse(string $output, bool $withLineCounts): array
    {
        $pieces = explode("\0", $output);
        $commits = [];
        $commit = null;
        $isFirstFile = false;

        for ($index = 0; $index < count($pieces); $index++) {
            $piece = $pieces[$index];

            if (str_starts_with($piece, self::COMMIT_MARKER)) {
                if ($commit !== null) {
                    $commits[] = self::withTotals($commit, $withLineCounts);
                }

                // The four fields after the sha are taken by position, never searched for,
                // so a subject that contains the marker cannot start a commit.
                [$parents, $author, $date, $subject] = array_pad(array_slice($pieces, $index + 1, 4), 4, '');
                $index += 4;
                $parentShas = $parents === '' ? [] : explode(' ', $parents);

                $commit = [
                    'sha' => substr($piece, 1),
                    'parent_sha' => $parentShas[0] ?? null,
                    'is_merge' => count($parentShas) > 1,
                    'author_name' => mb_substr(mb_scrub($author, 'UTF-8'), 0, 255),
                    'authored_at' => CarbonImmutable::parse($date)->utc(),
                    'subject' => mb_substr(mb_scrub($subject, 'UTF-8'), 0, 500),
                    'files' => [],
                ];
                $isFirstFile = true;

                continue;
            }

            // git puts one newline between a commit's fields and its list of files.
            if ($isFirstFile && str_starts_with($piece, "\n")) {
                $piece = substr($piece, 1);
            }

            if ($commit === null || $piece === '') {
                continue;
            }

            $isFirstFile = false;
            $file = $withLineCounts ? self::parseLineCounts($piece) : ['path' => $piece, 'insertions' => null, 'deletions' => null];

            if ($file !== null && strlen($file['path']) <= 1024) {
                $commit['files'][] = [...$file, 'path' => mb_scrub($file['path'], 'UTF-8')];
            }
        }

        if ($commit !== null) {
            $commits[] = self::withTotals($commit, $withLineCounts);
        }

        return $commits;
    }

    /**
     * One --numstat entry: "added<TAB>removed<TAB>path", with "-" instead of the numbers for a binary file.
     * The path may itself contain tabs, so the entry is cut into three pieces at most.
     *
     * @return array{path: string, insertions: int|null, deletions: int|null}|null
     */
    private static function parseLineCounts(string $piece): ?array
    {
        $fields = explode("\t", $piece, 3);

        if (count($fields) < 3) {
            return null;
        }

        [$insertions, $deletions, $path] = $fields;

        return [
            'path' => $path,
            'insertions' => ctype_digit($insertions) ? (int) $insertions : null,
            'deletions' => ctype_digit($deletions) ? (int) $deletions : null,
        ];
    }

    /**
     * Add a commit's counters: files changed and the sum of the lines added and removed.
     * A merge commit gets none; without line counts only the number of files is known.
     *
     * @param  array{sha: string, parent_sha: string|null, is_merge: bool, author_name: string, authored_at: CarbonImmutable, subject: string, files: list<array{path: string, insertions: int|null, deletions: int|null}>}  $commit
     * @return array{sha: string, parent_sha: string|null, is_merge: bool, author_name: string, authored_at: CarbonImmutable, subject: string, files_changed: int|null, insertions: int|null, deletions: int|null, files: list<array{path: string, insertions: int|null, deletions: int|null}>}
     */
    private static function withTotals(array $commit, bool $withLineCounts): array
    {
        if ($commit['is_merge']) {
            return [...$commit, 'files_changed' => null, 'insertions' => null, 'deletions' => null];
        }

        return [
            ...$commit,
            'files_changed' => count($commit['files']),
            'insertions' => $withLineCounts ? array_sum(array_column($commit['files'], 'insertions')) : null,
            'deletions' => $withLineCounts ? array_sum(array_column($commit['files'], 'deletions')) : null,
        ];
    }

    /**
     * Whether the clone has this commit, without asking GitHub for it (GIT_NO_LAZY_FETCH). The sha comes
     * from our database, but it is still checked to be a sha, so it can never be read by git as an option.
     */
    private function hasCommit(string $clonePath, string $sha): bool
    {
        return preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1
            && $this->git($clonePath, ['GIT_NO_LAZY_FETCH' => '1'])->run(['git', 'cat-file', '-e', "{$sha}^{commit}"])->successful();
    }

    /**
     * Download, in one request, the file contents that `git log --numstat` needs for the commits
     * it is about to read. The first commit it will NOT read (the one after the newest $limit) is
     * excluded together with everything older; `git backfill` still downloads the contents of the
     * commits right before the read ones, which the oldest read commit is compared with.
     *
     * --topo-order never lists a commit before the commits that came after it, so none of the
     * commits that `git log` reads can be older than the excluded one.
     *
     * A failure only loses the line counts (see read()), so it is logged and not thrown.
     *
     * @param  list<string>  $revisions
     */
    private function downloadFileContents(string $clonePath, array $revisions, int $limit): void
    {
        $firstSkipped = trim($this->git($clonePath)
            ->run(['git', 'rev-list', '--topo-order', '--max-count=1', "--skip={$limit}", ...$revisions, '--'])
            ->output());

        $range = $firstSkipped === '' ? $revisions : [...$revisions, "^{$firstSkipped}"];

        try {
            $result = $this->git($clonePath)
                ->timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
                ->run(['git', '-c', 'credential.helper=', '-c', 'core.askPass=', 'backfill', ...$range]);
        } catch (ProcessTimedOutException) {
            Log::warning('git backfill timed out.', ['clone' => $clonePath, 'timeout_seconds' => self::DOWNLOAD_TIMEOUT_SECONDS]);

            return;
        }

        if ($result->failed()) {
            Log::warning('git backfill failed.', [
                'clone' => $clonePath,
                'exit_code' => $result->exitCode(),
                'git_output' => Str::limit($result->errorOutput(), 1000),
            ]);
        }
    }

    /**
     * Run `git log` on the clone, offline: GIT_NO_LAZY_FETCH=1 stops git from downloading
     * a missing file content (the command fails instead), and log.showSignature=false keeps
     * a signature check from the server's own git settings out of the output.
     *
     * --no-renames: a renamed file counts as removed + added, because finding renames
     * would need the contents of files that were not downloaded.
     *
     * @param  list<string>  $revisions
     */
    private function log(string $clonePath, array $revisions, int $limit, string $filesOption): ProcessResult
    {
        return $this->git($clonePath, ['GIT_NO_LAZY_FETCH' => '1'])
            ->timeout(self::LOG_TIMEOUT_SECONDS)
            ->run([
                'git', '-c', 'log.showSignature=false', 'log',
                '-z', '--topo-order', "--max-count={$limit}", '--no-renames',
                '--format='.self::FORMAT, $filesOption,
                ...$revisions, '--',
            ]);
    }

    /**
     * A git command that runs in the clone's folder and can never ask for a password (see Git).
     *
     * @param  array<string, string>  $environment
     */
    private function git(string $clonePath, array $environment = []): PendingProcess
    {
        return Git::process($clonePath, $environment);
    }
}
