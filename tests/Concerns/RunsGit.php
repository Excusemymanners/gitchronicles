<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Process;

/**
 * Real git for the tests that need a repository: the jobs clone it, the analysis reads it.
 */
trait RunsGit
{
    /**
     * Turn a folder into a git repository on the "main" branch, with all its files in one commit.
     */
    protected function commitEverything(string $path, string $message = 'Primul commit'): void
    {
        $this->git($path, ['init', '--quiet', '-b', 'main']);
        $this->git($path, ['add', '--all']);
        $this->git($path, ['commit', '--quiet', '-m', $message]);
    }

    /**
     * Run a git command in the given folder and return its trimmed output.
     *
     * The machine's own git settings are ignored (no signing, no hooks from ~/.gitconfig),
     * and the author is fixed, so the commits are the same on any computer.
     *
     * @param  list<string>  $arguments
     */
    protected function git(string $path, array $arguments): string
    {
        return trim(Process::path($path)
            ->env([
                'GIT_CONFIG_GLOBAL' => '/dev/null',
                'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_AUTHOR_NAME' => 'Ana Popescu',
                'GIT_AUTHOR_EMAIL' => 'ana@example.com',
                'GIT_COMMITTER_NAME' => 'Ana Popescu',
                'GIT_COMMITTER_EMAIL' => 'ana@example.com',
            ])
            ->run(['git', ...$arguments])
            ->throw()
            ->output());
    }
}
