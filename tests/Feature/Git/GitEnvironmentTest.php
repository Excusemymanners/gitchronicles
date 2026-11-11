<?php

namespace Tests\Feature\Git;

use App\Git\ChangeSet;
use App\Git\CommitHistory;
use App\Git\LocalClone;
use App\Git\RemoteHead;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Every git command GitChronicle runs must be unable to stop at a password prompt (Git::process()):
 * a blobless clone may go to GitHub even from a command that only reads it.
 */
class GitEnvironmentTest extends TestCase
{
    public function test_every_git_command_runs_with_terminal_prompts_turned_off(): void
    {
        Process::fake(['*' => Process::result(output: '')]);
        $clone = new LocalClone('/srv/clone');

        $clone->clone('https://github.com/acme/magazin', 10);
        $clone->fetch(10);
        $clone->moveTo('FETCH_HEAD', 10);
        $clone->head();
        $clone->branch();
        $clone->trackedFileCount();
        ChangeSet::between('/srv/clone', str_repeat('a', 40), str_repeat('b', 40));
        RemoteHead::of('https://github.com/acme/magazin');
        app(CommitHistory::class)->read('/srv/clone', 10, str_repeat('a', 40));

        Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command[0] === 'git', 12);
        Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command[0] === 'git'
            && (($process->environment['GIT_TERMINAL_PROMPT'] ?? null) !== '0' || ($process->environment['GIT_ASKPASS'] ?? null) !== ''));
        // The offline commands keep their own variable next to the two common ones.
        Process::assertRan(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'diff'
            && $process->environment === ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '', 'GIT_NO_LAZY_FETCH' => '1']);
    }
}
