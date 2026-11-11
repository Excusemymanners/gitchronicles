<?php

namespace App\Git;

use App\Models\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;

/**
 * The clone of a repository on this server (storage/app/private/repositories/{id}) and the git
 * commands that bring it up to date: the first `git clone`, later a `git fetch` and a move to the
 * newest commit, which is much cheaper than cloning again (Faza 9).
 *
 * Every command is an array (never a shell string), so a URL cannot run other commands. The commands
 * that talk to GitHub cannot ask for a password and cannot find one: credential.helper, core.askPass
 * and GIT_ASKPASS are emptied and GIT_TERMINAL_PROMPT=0 (Git::process(), used by every command here),
 * so only public repositories work, even when the worker runs in a terminal logged in to GitHub
 * (VS Code sets GIT_ASKPASS).
 */
final class LocalClone
{
    /**
     * git options that keep it from finding saved credentials.
     */
    private const NO_CREDENTIALS = ['-c', 'credential.helper=', '-c', 'core.askPass='];

    /**
     * Create the clone object for a folder (the folder itself may not exist yet).
     */
    public function __construct(private string $path) {}

    /**
     * The clone of a repository.
     */
    public static function of(Repository $repository): self
    {
        return new self($repository->clonePath());
    }

    /**
     * Whether the folder holds a clone that can simply be updated: a git repository with a commit,
     * cloned from this same URL. Anything else (no folder, a clone cut short, another origin)
     * is deleted and cloned again by the caller.
     */
    public function isHealthy(string $url): bool
    {
        if (! is_dir($this->path.'/.git')) {
            return false;
        }

        return $this->head() !== null
            && trim(Git::process($this->path)->run(['git', 'config', '--get', 'remote.origin.url'])->output()) === $url;
    }

    /**
     * `git clone` into the folder: --filter=blob:none downloads file contents only for the latest
     * commit and --no-tags skips the tags, so the clone is small and fast; every commit is still there.
     * "--" tells git that what follows is the URL and the folder, never an option.
     */
    public function clone(string $url, int $timeoutSeconds): ProcessResult
    {
        return $this->network($timeoutSeconds)->run([
            'git', ...self::NO_CREDENTIALS,
            'clone', '--filter=blob:none', '--no-tags', '--quiet',
            '--', $url, $this->path,
        ]);
    }

    /**
     * `git fetch` the newest commit of the remote's main branch (its HEAD), again without file
     * contents and tags. The commit lands in FETCH_HEAD; moveTo() then goes to it.
     */
    public function fetch(int $timeoutSeconds): ProcessResult
    {
        return $this->network($timeoutSeconds)->path($this->path)->run([
            'git', ...self::NO_CREDENTIALS,
            'fetch', '--filter=blob:none', '--no-tags', '--quiet', 'origin', 'HEAD',
        ]);
    }

    /**
     * Move the clone's branch and its files to a commit ("FETCH_HEAD" after a fetch, or a sha).
     * The clone has no file contents for older commits, so git downloads the ones this commit
     * needs: that is why this command may use the network too.
     */
    public function moveTo(string $commit, int $timeoutSeconds): ProcessResult
    {
        return $this->network($timeoutSeconds)->path($this->path)->run([
            'git', ...self::NO_CREDENTIALS,
            'reset', '--hard', '--quiet', $commit, '--',
        ]);
    }

    /**
     * The sha of the commit the clone is at, or null when it has no commit (an empty repository).
     */
    public function head(): ?string
    {
        $result = Git::process($this->path)->run(['git', 'rev-parse', '--verify', '--quiet', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * The name of the checked-out branch: the remote's main branch at the time of the clone.
     */
    public function branch(): string
    {
        return trim(Git::process($this->path)->run(['git', 'symbolic-ref', '--short', 'HEAD'])->throw()->output());
    }

    /**
     * How many files git tracks at the current commit.
     */
    public function trackedFileCount(): int
    {
        return substr_count(Git::process($this->path)->run(['git', 'ls-files', '-z'])->throw()->output(), "\0");
    }

    /**
     * A git process that may talk to GitHub: with a time limit and, like every git process (see Git),
     * unable to ask for a password.
     */
    private function network(int $timeoutSeconds): PendingProcess
    {
        return Git::process()->timeout($timeoutSeconds);
    }
}
