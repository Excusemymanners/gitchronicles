<?php

namespace App\Jobs;

use App\Enums\RepositoryStatus;
use App\Exceptions\RepositoryProcessingException;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Step 1 of the pipeline: clone the repository from GitHub into storage/app/private/repositories/{id}
 * and remember which commit it reached (last_sha) and its main branch (default_branch).
 *
 * The job is idempotent: every attempt first deletes what a previous attempt left behind.
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
        $this->repository->update(['status' => RepositoryStatus::Cloning]);

        Storage::disk('local')->deleteDirectory($this->repository->cloneDirectory());

        if (! $this->cloneFromGitHub()) {
            return;
        }

        $clonePath = $this->repository->clonePath();
        $head = Process::path($clonePath)->run(['git', 'rev-parse', 'HEAD']);

        if ($head->failed()) {
            $this->fail(new RepositoryProcessingException('Repository-ul nu are încă niciun commit, deci nu avem ce documenta.'));

            return;
        }

        $sha = trim($head->output());
        $defaultBranch = trim(Process::path($clonePath)->run(['git', 'symbolic-ref', '--short', 'HEAD'])->throw()->output());
        $trackedFiles = Process::path($clonePath)->run(['git', 'ls-files', '-z'])->throw()->output();

        $this->repository->update([
            'last_sha' => $sha,
            'default_branch' => $defaultBranch,
            'cloned_at' => now(),
        ]);

        $this->run->update([
            'to_sha' => $sha,
            'files_total' => substr_count($trackedFiles, "\0"),
        ]);
    }

    /**
     * Run `git clone` and return whether it worked. On failure the job is already failed
     * (return false) or an exception is thrown for a second attempt.
     *
     * - the command is an array, never a shell string, so the URL cannot run other commands,
     *   and "--" tells git that what follows is the URL and the folder, never an option;
     * - credential.helper, core.askPass and GIT_ASKPASS are emptied and GIT_TERMINAL_PROMPT=0,
     *   so git never asks for (or finds) a password: only public repositories can be cloned,
     *   even when the worker runs in a terminal logged in to GitHub (VS Code sets GIT_ASKPASS);
     * - --filter=blob:none downloads file contents only for the latest commit, and --no-tags skips tags,
     *   so the clone is small and fast; the full history of commits is still there.
     */
    private function cloneFromGitHub(): bool
    {
        try {
            $result = Process::timeout(self::CLONE_TIMEOUT_SECONDS)
                ->env(['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => ''])
                ->run([
                    'git', '-c', 'credential.helper=', '-c', 'core.askPass=',
                    'clone', '--filter=blob:none', '--no-tags', '--quiet',
                    '--', $this->repository->url, $this->repository->clonePath(),
                ]);
        } catch (ProcessTimedOutException) {
            Log::warning('git clone timed out.', [
                'repository_id' => $this->repository->id,
                'timeout_seconds' => self::CLONE_TIMEOUT_SECONDS,
            ]);

            $minutes = intdiv(self::CLONE_TIMEOUT_SECONDS, 60);
            $this->fail(new RepositoryProcessingException("Clonarea a durat mai mult de {$minutes} minute, așa că am oprit-o. Repository-ul este probabil prea mare."));

            return false;
        }

        if ($result->successful()) {
            return true;
        }

        Log::warning('git clone failed.', [
            'repository_id' => $this->repository->id,
            'exit_code' => $result->exitCode(),
            'git_output' => $result->errorOutput(),
        ]);

        if ($this->isMissingOrPrivate($result->errorOutput())) {
            $this->fail(new RepositoryProcessingException('Repository-ul nu există pe GitHub sau este privat. GitChronicle poate clona doar repository-uri publice.'));

            return false;
        }

        throw new RepositoryProcessingException('Nu am putut clona repository-ul. Încearcă din nou peste câteva minute.');
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
