<?php

namespace App\Jobs;

use App\Enums\ProcessingRunStatus;
use App\Git\CommitHistory;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step 3 of the pipeline: import the git history of the clone (CommitHistory) into the commits
 * and commit_files tables, for the „Istoric” tab, the „Activitate” and the „Zone fierbinți”.
 *
 * The first import reads the newest MAX_COMMITS commits; a later one reads only the commits
 * that came after the last processed commit (see lastImportedSha()).
 *
 * The job is idempotent: a commit is saved by its sha (upsert), so importing it again only
 * updates the same row, and its list of files is replaced, never doubled.
 */
class ImportCommits implements ShouldQueue
{
    use Queueable;

    /**
     * How many commits one import reads at most: enough for a year or more of a busy project,
     * and small enough to download their file contents in a few seconds.
     */
    public const MAX_COMMITS = 500;

    /**
     * How many rows one INSERT saves.
     */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * One attempt: git reads the local clone; the only network part (the file contents for the
     * line counts) has its own fallback in CommitHistory, so an error would happen again.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: the download and git log (CommitHistory's
     * timeouts) plus a margin for saving.
     */
    public int $timeout = 300;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Repository $repository,
        public ProcessingRun $run,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CommitHistory $history): void
    {
        $startedAt = microtime(true);
        $since = $this->lastImportedSha();

        $commits = $history->read($this->repository->clonePath(), self::MAX_COMMITS, $since);

        DB::transaction(fn () => $this->save($commits));

        Log::info('Commits imported.', [
            'repository_id' => $this->repository->id,
            'since' => $since,
            'commits' => count($commits),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * The commit up to which the history is already imported: the newest commit (to_sha) of the
     * last run that succeeded, if that commit is in the database. Null means "import the newest
     * MAX_COMMITS": on the first import, or when that commit is missing for any reason.
     */
    private function lastImportedSha(): ?string
    {
        $sha = $this->repository->processingRuns()
            ->where('status', ProcessingRunStatus::Succeeded)
            ->whereKeyNot($this->run->id)
            ->whereNotNull('to_sha')
            ->latest('id')
            ->value('to_sha');

        if ($sha === null || ! $this->repository->commits()->where('sha', $sha)->exists()) {
            return null;
        }

        return $sha;
    }

    /**
     * Save the commits (insert the new ones, update the known ones) and replace their files.
     * It runs inside a transaction: the pages see the import whole or not at all.
     *
     * @param  list<array{sha: string, parent_sha: string|null, is_merge: bool, author_name: string, authored_at: \DateTimeInterface, subject: string, files_changed: int|null, insertions: int|null, deletions: int|null, files: list<array{path: string, insertions: int|null, deletions: int|null}>}>  $commits
     */
    private function save(array $commits): void
    {
        $commitRows = array_map(fn (array $commit): array => [
            'repository_id' => $this->repository->id,
            'sha' => $commit['sha'],
            'parent_sha' => $commit['parent_sha'],
            'is_merge' => $commit['is_merge'],
            'author_name' => $commit['author_name'],
            'authored_at' => $commit['authored_at'],
            'subject' => $commit['subject'],
            'files_changed' => $commit['files_changed'],
            'insertions' => $commit['insertions'],
            'deletions' => $commit['deletions'],
        ], $commits);

        foreach (array_chunk($commitRows, self::INSERT_CHUNK_SIZE) as $chunk) {
            Commit::upsert($chunk, uniqueBy: ['repository_id', 'sha'], update: [
                'parent_sha', 'is_merge', 'author_name', 'authored_at', 'subject', 'files_changed', 'insertions', 'deletions',
            ]);
        }

        $commitIds = $this->repository->commits()
            ->whereIn('sha', array_column($commits, 'sha'))
            ->pluck('id', 'sha');

        CommitFile::whereIn('commit_id', $commitIds->values())->delete();

        $fileRows = [];

        foreach ($commits as $commit) {
            foreach ($commit['files'] as $file) {
                $fileRows[] = [
                    'commit_id' => $commitIds[$commit['sha']],
                    'path' => $file['path'],
                    'insertions' => $file['insertions'],
                    'deletions' => $file['deletions'],
                ];
            }
        }

        foreach (array_chunk($fileRows, self::INSERT_CHUNK_SIZE) as $chunk) {
            CommitFile::insert($chunk);
        }
    }
}
