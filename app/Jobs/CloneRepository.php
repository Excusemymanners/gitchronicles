<?php

namespace App\Jobs;

use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Enums\ProcessingRunStatus;
use App\Enums\RepositoryStatus;
use App\Exceptions\RepositoryProcessingException;
use App\Exceptions\RepositoryTooLarge;
use App\Git\DiskUsage;
use App\Git\LocalClone;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Step 1 of the pipeline: bring the repository's code to storage/app/private/repositories/{id}
 * and remember which commit it reached (last_sha) and its main branch (default_branch).
 *
 * - The first time (or when the folder does not hold a usable clone) the repository is cloned.
 * - After that, an existing clone is only updated (Faza 9): `git fetch` brings the new commits and
 *   the clone moves to the newest one. This is much cheaper than deleting it and cloning again.
 * - When an incremental run finds the same commit as the last successful run, there is nothing new:
 *   the run is closed as "skipped" and the rest of the pipeline is dropped, so no step runs and
 *   Claude is never called. The only exception is a repository with pages marked stale (Claude could
 *   not write them last time) while an AI driver is configured: then the pipeline goes on, with no
 *   changed files, to try them again.
 *
 * The job is idempotent: a second attempt replaces whatever the first one left with a fresh clone.
 *
 * When git fails, the user gets a short Romanian message (repositories.error) and the log gets
 * the details (exit code and git's own output). Errors that cannot go away by themselves
 * (a missing or private repository, a clone that is too slow, an empty repository) fail the job
 * right away; any other git error is thrown, so the queue tries once more after $backoff seconds.
 */
class CloneRepository implements ShouldQueue
{
    use Queueable;

    /**
     * After this many seconds git is stopped: a bigger repository is too big for GitChronicle.
     */
    public const CLONE_TIMEOUT_SECONDS = 240;

    /**
     * Two attempts: a network hiccup gets a second chance.
     */
    public int $tries = 2;

    /**
     * Seconds to wait before the second attempt.
     */
    public int $backoff = 30;

    /**
     * The maximum number of seconds the job may run: the clone plus a margin for the rest.
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
    public function handle(): void
    {
        $this->repository->update(['status' => $this->run->statusFor(RepositoryStatus::Cloning)]);

        $clone = $this->localClone();

        // Only the first attempt trusts an existing clone: if updating it failed, the second one starts clean.
        $isUpdate = $this->attempts() === 1 && $clone->isHealthy($this->repository->url);

        if (! $isUpdate) {
            Storage::disk('local')->deleteDirectory($this->repository->cloneDirectory());
        }

        if (! $isUpdate && $this->storageIsFull()) {
            $this->fail(RepositoryTooLarge::because('storage_full'));

            return;
        }

        try {
            // The files are written to disk only after the size of the commit was checked (TreeInspector).
            $worked = $isUpdate
                ? $this->git(fn () => $clone->fetch(self::CLONE_TIMEOUT_SECONDS), isUpdate: true)
                    && $this->checkOut($clone, 'FETCH_HEAD', isUpdate: true)
                : $this->git(fn () => $clone->clone($this->repository->url, self::CLONE_TIMEOUT_SECONDS), isUpdate: false)
                    && $this->checkOut($clone, 'HEAD', isUpdate: false);
        } catch (RepositoryTooLarge $exception) {
            $this->discardClone();
            $this->fail($exception);

            return;
        }

        if (! $worked) {
            return;
        }

        $sha = $clone->head();

        if ($sha === null) {
            $this->fail(RepositoryProcessingException::because('no_commits'));

            return;
        }

        $this->repository->update([
            'last_sha' => $sha,
            'default_branch' => $clone->branch(),
            'cloned_at' => now(),
        ]);

        $this->run->update([
            'to_sha' => $sha,
            'files_total' => $clone->trackedFileCount(),
        ]);

        if ($this->run->isIncremental() && $sha === $this->run->from_sha && ! $this->canRetryStalePages()) {
            $this->finishWithNothingNew();
        }
    }

    /**
     * The clone folder of the repository (a method of its own so a test can replace it).
     */
    protected function localClone(): LocalClone
    {
        return LocalClone::of($this->repository);
    }

    /**
     * Check the size of the commit, then write its files to disk. A repository over the limits is refused
     * before it touches the disk.
     *
     * @throws RepositoryTooLarge
     */
    private function checkOut(LocalClone $clone, string $commit, bool $isUpdate): bool
    {
        if ($clone->head() === null && ! $isUpdate) {
            return true;
        }

        $clone->assertCommitFits($commit);

        if (! $this->git(fn () => $clone->moveTo($commit, self::CLONE_TIMEOUT_SECONDS), $isUpdate)) {
            return false;
        }

        // The real folder, as the disk counts it, is checked once more after the checkout.
        $limit = (int) config('gitchronicle.git.max_disk_bytes');

        if ($limit > 0 && DiskUsage::of($this->repository->clonePath()) > $limit) {
            throw RepositoryTooLarge::because('too_large', ['max' => intdiv($limit, 1024 * 1024)]);
        }

        return true;
    }

    /**
     * Whether all the clones together already use the storage budget (config gitchronicle.git.storage_budget_bytes).
     */
    private function storageIsFull(): bool
    {
        $budget = (int) config('gitchronicle.git.storage_budget_bytes');

        return $budget > 0 && DiskUsage::of(Storage::disk('local')->path('repositories')) >= $budget;
    }

    /**
     * Delete what a failed or stopped clone left on disk: nobody can use it, and it would keep taking space.
     */
    private function discardClone(): void
    {
        Storage::disk('local')->deleteDirectory($this->repository->cloneDirectory());
    }

    /**
     * Whether a run at the same commit still has something to do: pages marked stale that Claude can
     * try to write again. Without an AI driver (AI_DRIVER=none) they would only stay stale, so the
     * run is "nothing new" like any other.
     */
    private function canRetryStalePages(): bool
    {
        return ! app(TextGenerator::class) instanceof NullTextGenerator
            && Document::whereBelongsTo($this->repository)->where('is_stale', true)->exists();
    }

    /**
     * The clone is at the same commit as the last successful run: close the run as "skipped", put the
     * repository back to "ready" and drop the steps that were chained after this one.
     */
    private function finishWithNothingNew(): void
    {
        $this->repository->update(['status' => RepositoryStatus::Ready]);
        $this->run->finish(ProcessingRunStatus::Skipped);

        // The next steps of the chain travel with the job (Queueable::$chained); without them the chain ends here.
        $this->chained = [];
    }

    /**
     * Run one git command (the clone, the fetch or the move to the new commit) and return whether it
     * worked. On failure the job is already failed (return false) or an exception is thrown for a
     * second attempt. LocalClone explains the commands themselves.
     *
     * @param  Closure(): ProcessResult  $command
     * @param  bool  $isUpdate  the command updates an existing clone (the messages say so)
     */
    private function git(Closure $command, bool $isUpdate): bool
    {
        try {
            $result = $command();
        } catch (ProcessTimedOutException) {
            Log::warning('git clone timed out.', [
                'repository_id' => $this->repository->id,
                'timeout_seconds' => self::CLONE_TIMEOUT_SECONDS,
            ]);

            $this->discardClone();
            $minutes = intdiv(self::CLONE_TIMEOUT_SECONDS, 60);
            $this->fail(RepositoryProcessingException::because($isUpdate ? 'fetch_timeout' : 'clone_timeout', ['minutes' => $minutes]));

            return false;
        }

        if ($result->successful()) {
            return true;
        }

        Log::warning('git clone failed.', [
            'repository_id' => $this->repository->id,
            'is_update' => $isUpdate,
            'exit_code' => $result->exitCode(),
            'git_output' => $result->errorOutput(),
        ]);

        if ($this->isMissingOrPrivate($result->errorOutput())) {
            $this->fail(RepositoryProcessingException::because('not_public'));

            return false;
        }

        throw RepositoryProcessingException::because($isUpdate ? 'fetch_failed' : 'clone_failed');
    }

    /**
     * Whether git's error says the repository cannot be reached without a password.
     * GitHub answers a private and a missing repository the same way: it asks for credentials,
     * which git cannot give ("could not read Username"). "does not exist" is a missing local folder.
     */
    private function isMissingOrPrivate(string $gitOutput): bool
    {
        return Str::contains($gitOutput, [
            'Repository not found',
            'could not read Username',
            'Authentication failed',
            'does not exist',
        ], ignoreCase: true);
    }
}
