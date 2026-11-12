<?php

namespace Tests\Feature\Git;

use App\Git\ChangeSet;
use App\Git\CommitHistory;
use App\Git\Git;
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
            && ($process->environment['GIT_NO_LAZY_FETCH'] ?? null) === '1');
    }

    public function test_git_runs_in_a_closed_room_without_the_workers_secrets_or_the_computers_settings(): void
    {
        putenv('APP_KEY=base64:secret');
        $_ENV['DB_PASSWORD'] = 'hunter2';
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-1';

        try {
            $environment = Git::environment();
        } finally {
            putenv('APP_KEY');
            unset($_ENV['DB_PASSWORD'], $_ENV['ANTHROPIC_API_KEY']);
        }

        // false = removed from the child process.
        $this->assertFalse($environment['DB_PASSWORD']);
        $this->assertFalse($environment['ANTHROPIC_API_KEY']);
        $this->assertFalse($environment['APP_KEY']);
        $this->assertSame('/dev/null', $environment['GIT_CONFIG_GLOBAL']);
        $this->assertSame('1', $environment['GIT_CONFIG_NOSYSTEM']);
        $this->assertSame('1', $environment['GIT_LFS_SKIP_SMUDGE']);
        $this->assertArrayNotHasKey('PATH', array_filter($environment, fn ($value) => $value === false));
    }

    public function test_hooks_symlinks_and_unlisted_protocols_are_turned_off_for_every_command(): void
    {
        config(['gitchronicle.git.protocols' => 'https']);
        $environment = Git::environment();
        $settings = [];

        for ($i = 0; $i < (int) $environment['GIT_CONFIG_COUNT']; $i++) {
            $settings[$environment["GIT_CONFIG_KEY_{$i}"]] = $environment["GIT_CONFIG_VALUE_{$i}"];
        }

        $this->assertSame('/dev/null', $settings['core.hooksPath']);
        $this->assertSame('false', $settings['core.symlinks']);
        $this->assertSame('never', $settings['protocol.allow']);
        $this->assertSame('always', $settings['protocol.https.allow']);
        $this->assertArrayNotHasKey('protocol.file.allow', $settings);
    }

    public function test_real_git_refuses_the_ssh_and_ext_protocols_and_does_not_run_the_users_global_filters(): void
    {
        config(['gitchronicle.git.protocols' => 'https']);

        $ext = Git::process()->timeout(20)->run(['git', 'ls-remote', '--', 'ext::sh -c "touch /tmp/gc-pwned"', 'HEAD']);
        $this->assertTrue($ext->failed());
        $this->assertFileDoesNotExist('/tmp/gc-pwned');

        $file = Git::process()->timeout(20)->run(['git', 'ls-remote', '--', sys_get_temp_dir(), 'HEAD']);
        $this->assertStringContainsString('not allowed', $file->errorOutput());
    }
}
