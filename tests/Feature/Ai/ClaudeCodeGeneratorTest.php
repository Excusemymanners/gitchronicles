<?php

namespace Tests\Feature\Ai;

use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\ClaudeCodeGenerator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The Claude Code CLI is never started: Process::fake() answers in its place with the JSON
 * the CLI prints in `--output-format json` mode (tests/Fixtures/ai/claude-code-success.json).
 */
class ClaudeCodeGeneratorTest extends TestCase
{
    private const SYSTEM_PROMPT = 'Scrii documentație tehnică în română.';

    private const USER_TEXT = 'Codul modulului app/Jobs: ...';

    public function test_the_child_process_gets_only_an_allowlist_of_environment_variables(): void
    {
        $secrets = ['APP_KEY' => 'base64:secret', 'DB_PASSWORD' => 'hunter2', 'AWS_SECRET_ACCESS_KEY' => 'aws', 'CLAUDE_CODE_USE_BEDROCK' => '1', 'ANTHROPIC_BASE_URL' => 'https://evil.example'];

        foreach ($secrets as $name => $value) {
            putenv("{$name}={$value}");
        }

        try {
            $ranProcess = null;
            Process::fake(function (PendingProcess $process) use (&$ranProcess) {
                $ranProcess = $process;

                return Process::result(File::get(base_path('tests/Fixtures/ai/claude-code-success.json')));
            });

            $this->generator()->generate(self::SYSTEM_PROMPT, self::USER_TEXT);
        } finally {
            foreach (array_keys($secrets) as $name) {
                putenv($name);
            }
        }

        foreach (array_keys($secrets) as $name) {
            $this->assertFalse($ranProcess->environment[$name] ?? null, "{$name} must be removed from the child environment.");
        }

        foreach (['HOME', 'PATH'] as $kept) {
            $this->assertArrayNotHasKey($kept, $ranProcess->environment, "{$kept} must be inherited.");
        }
    }

    public function test_runs_the_cli_once_with_the_text_on_stdin_and_returns_its_answer_and_usage(): void
    {
        $ranProcess = null;
        $directoryWasEmpty = false;
        Process::fake(function (PendingProcess $process) use (&$ranProcess, &$directoryWasEmpty) {
            $ranProcess = $process;
            $directoryWasEmpty = File::isDirectory($process->path) && File::isEmptyDirectory($process->path);

            return Process::result(File::get(base_path('tests/Fixtures/ai/claude-code-success.json')));
        });

        $result = $this->generator()->generate(self::SYSTEM_PROMPT, self::USER_TEXT);

        $this->assertEquals(new AiResult(
            text: "## Rol\n\nModulul `app/Jobs` rulează pașii procesării în fundal.",
            inputTokens: 12 + 2048 + 4096,
            outputTokens: 230,
            costUsdEquivalent: 0.0421,
            model: 'claude-opus-5-5',
            driver: 'claude-code',
        ), $result);
        Process::assertRanTimes(fn () => true, 1);
        $this->assertSame([
            '/opt/claude/bin/claude',
            '-p',
            '--output-format', 'json',
            '--max-turns', '1',
            '--tools', '',
            '--strict-mcp-config',
            '--setting-sources', '',
            '--no-session-persistence',
            '--model', 'claude-opus-5-5',
            '--effort', 'medium',
            '--system-prompt', self::SYSTEM_PROMPT,
        ], $ranProcess->command);
        $this->assertSame(self::USER_TEXT, $ranProcess->input);
        $this->assertFalse($ranProcess->environment['ANTHROPIC_API_KEY']);
        $this->assertFalse($ranProcess->environment['ANTHROPIC_AUTH_TOKEN']);
        $this->assertSame('1', $ranProcess->environment['DISABLE_AUTOUPDATER']);
        $this->assertSame(ClaudeCodeGenerator::TIMEOUT_SECONDS, $ranProcess->timeout);
        $this->assertTrue($directoryWasEmpty, 'The CLI must run in an empty folder.');
        $this->assertStringStartsNotWith(base_path(), $ranProcess->path);
        $this->assertDirectoryDoesNotExist($ranProcess->path);
    }

    public function test_the_caller_can_ask_for_a_shorter_timeout(): void
    {
        $ranProcess = null;
        Process::fake(function (PendingProcess $process) use (&$ranProcess) {
            $ranProcess = $process;

            return Process::result(File::get(base_path('tests/Fixtures/ai/claude-code-success.json')));
        });

        $this->generator()->generate(self::SYSTEM_PROMPT, self::USER_TEXT, 60);

        $this->assertSame(60, $ranProcess->timeout);
    }

    public function test_a_used_up_subscription_fails_with_a_retryable_limit_message(): void
    {
        Process::fake(['*' => Process::result($this->errorJson('Claude AI usage limit reached|1794300000'), exitCode: 1)]);

        $exception = $this->generateExpectingFailure();

        $this->assertSame('The Claude subscription limit has been reached; try again later.', $exception->getMessage());
        $this->assertTrue($exception->retryable);
    }

    public function test_an_answer_cut_at_max_tokens_is_rejected(): void
    {
        Process::fake(['*' => Process::result(json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'is_error' => false,
            'result' => '## Rol\n\nModulul',
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 16000],
        ]))]);

        $exception = $this->generateExpectingFailure();

        $this->assertSame('Claude’s answer was too long and stopped halfway.', $exception->getMessage());
        $this->assertFalse($exception->retryable);
    }

    public function test_output_that_is_not_json_fails_with_the_generic_message_and_logs_no_prompt(): void
    {
        Log::spy();
        Process::fake(['*' => Process::result('', errorOutput: 'Error: something broke', exitCode: 1)]);

        $exception = $this->generateExpectingFailure();

        $this->assertSame(__(AiException::GENERIC_MESSAGE), $exception->getMessage());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Claude Code did not return JSON.'
            && $context['exit_code'] === 1
            && ! str_contains(json_encode($context), self::USER_TEXT)
            && ! str_contains(json_encode($context), self::SYSTEM_PROMPT));
    }

    public function test_an_error_reported_by_the_cli_fails_with_the_generic_message_and_cleans_up_its_folder(): void
    {
        $workingDirectory = null;
        Process::fake(function (PendingProcess $process) use (&$workingDirectory) {
            $workingDirectory = $process->path;

            return Process::result($this->errorJson('Invalid API key · Please run /login'), exitCode: 1);
        });

        $exception = $this->generateExpectingFailure();

        $this->assertSame(__(AiException::GENERIC_MESSAGE), $exception->getMessage());
        $this->assertFalse($exception->retryable);
        $this->assertDirectoryDoesNotExist($workingDirectory);
    }

    /**
     * A generator with a fixed executable path and the default model settings.
     */
    private function generator(): ClaudeCodeGenerator
    {
        return new ClaudeCodeGenerator(binary: '/opt/claude/bin/claude', model: 'claude-opus-5-5', effort: 'medium');
    }

    /**
     * Call the generator and return the AiException it throws (the test fails if it does not throw).
     */
    private function generateExpectingFailure(): AiException
    {
        try {
            $this->generator()->generate(self::SYSTEM_PROMPT, self::USER_TEXT);
        } catch (AiException $exception) {
            return $exception;
        }

        $this->fail('The generator did not throw an AiException.');
    }

    /**
     * The JSON the CLI prints when it reports an error: is_error is true and "result" holds the reason.
     */
    private function errorJson(string $reason): string
    {
        return json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'is_error' => true,
            'result' => $reason,
            'stop_reason' => 'stop_sequence',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ]);
    }
}
