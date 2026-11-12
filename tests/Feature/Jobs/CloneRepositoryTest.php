<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\RepositoryProcessingException;
use App\Git\LocalClone;
use App\Jobs\CloneRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * The decisions CloneRepository makes about git's answers. git itself is faked here
 * (Process::fake), because GitHub's answers cannot be produced without the network;
 * ProcessRepositoryTest runs the real git on a local repository.
 */
class CloneRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_git_runs_as_an_array_command_that_can_never_ask_for_a_password(): void
    {
        Storage::fake('local');
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result()]);
        $repository = Repository::factory()->create(['url' => 'https://github.com/laravel/framework']);
        $run = ProcessingRun::factory()->for($repository)->create();

        (new CloneRepository($repository, $run))->withFakeQueueInteractions()->handle();

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'git', '-c', 'credential.helper=', '-c', 'core.askPass=',
            'clone', '--no-checkout', '--filter=blob:none', '--no-tags', '--quiet',
            '--', 'https://github.com/laravel/framework', Storage::disk('local')->path("repositories/{$repository->id}"),
        ]
            && $process->environment['GIT_TERMINAL_PROMPT'] === '0'
            && $process->environment['GIT_ASKPASS'] === ''
            && $process->environment['GIT_CONFIG_GLOBAL'] === '/dev/null'
            && $process->timeout === 240);
    }

    public function test_a_private_or_missing_github_repository_fails_the_job_without_another_attempt(): void
    {
        Storage::fake('local');
        Process::fake(['*' => Process::result(
            errorOutput: "fatal: could not read Username for 'https://github.com': terminal prompts disabled",
            exitCode: 128,
        )]);
        $repository = Repository::factory()->create();
        $run = ProcessingRun::factory()->for($repository)->create();
        $job = (new CloneRepository($repository, $run))->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailedWith(new RepositoryProcessingException(
            StoredMessage::make('repositories.errors.not_public'),
        ));
    }

    public function test_a_clone_slower_than_the_time_limit_fails_the_job_without_another_attempt_and_deletes_the_partial_clone(): void
    {
        Storage::fake('local');
        $repository = Repository::factory()->create();
        $run = ProcessingRun::factory()->for($repository)->create();
        Storage::disk('local')->put("repositories/{$repository->id}/half-a-clone.pack", 'partial');
        $job = new class($repository, $run) extends CloneRepository
        {
            protected function localClone(): LocalClone
            {
                return new class(Storage::disk('local')->path($this->repository->cloneDirectory())) extends LocalClone
                {
                    public function clone(string $url, int $timeoutSeconds): ProcessResult
                    {
                        throw new ProcessTimedOutException(
                            new SymfonyTimeoutException(new SymfonyProcess(['git', 'clone']), SymfonyTimeoutException::TYPE_GENERAL),
                            Process::result(),
                        );
                    }
                };
            }
        };
        $job->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailedWith(new RepositoryProcessingException(
            StoredMessage::make('repositories.errors.clone_timeout', ['minutes' => 4]),
        ));
        Storage::disk('local')->assertMissing("repositories/{$repository->id}/half-a-clone.pack");
    }

    public function test_another_git_error_is_thrown_so_the_queue_tries_again(): void
    {
        Storage::fake('local');
        Process::fake(['*' => Process::result(
            errorOutput: "fatal: unable to access 'https://github.com/laravel/framework/': Could not resolve host: github.com",
            exitCode: 128,
        )]);
        $repository = Repository::factory()->create();
        $run = ProcessingRun::factory()->for($repository)->create();
        $job = (new CloneRepository($repository, $run))->withFakeQueueInteractions();

        $this->assertThrows(
            fn () => $job->handle(),
            RepositoryProcessingException::class,
            StoredMessage::make('repositories.errors.clone_failed'),
        );
        $job->assertNotFailed();
    }
}
