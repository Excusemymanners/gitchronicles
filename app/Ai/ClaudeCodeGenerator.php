<?php

namespace App\Ai;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Writes the text with the Claude Code CLI installed on this computer, logged in with a Claude
 * subscription: no API key and no API credits are used (AI_DRIVER=claude-code).
 *
 * One call = one run of `claude -p` (print mode) that answers once and exits:
 *
 * - the command is an array, never a shell string; the user text goes on STDIN, not on the command line;
 * - --tools "" and --max-turns 1: no tools at all (no file reading, no shell), only one answer;
 * - --strict-mcp-config and --setting-sources "": no MCP servers and none of this computer's settings;
 * - --no-session-persistence: the conversation is not saved in ~/.claude;
 * - it runs in a new empty folder in the system's temporary directory, deleted after the call,
 *   so it cannot see this project, its .env or any CLAUDE.md;
 * - ANTHROPIC_API_KEY and ANTHROPIC_AUTH_TOKEN are removed from its environment, so it always
 *   uses the subscription, even when the .env of the app has an API key.
 *
 * The system prompt travels as a command line argument, so it must stay short (Linux allows
 * 128 KB per argument); the long part (the code) belongs in $user.
 */
final class ClaudeCodeGenerator implements TextGenerator
{
    /**
     * After this many seconds the CLI is stopped.
     */
    public const TIMEOUT_SECONDS = 300;

    /**
     * Create a new generator. The values come from config/gitchronicle.php (AppServiceProvider).
     */
    public function __construct(
        private string $binary,
        private string $model,
        private string $effort,
    ) {}

    /**
     * Run the CLI once and return its answer.
     *
     * @throws AiException when the CLI fails, times out or reports an error
     */
    public function generate(string $system, string $user): AiResult
    {
        $workingDirectory = sys_get_temp_dir().'/gitchronicle-ai-'.Str::random(16);
        File::ensureDirectoryExists($workingDirectory);

        try {
            $result = Process::path($workingDirectory)
                ->timeout(self::TIMEOUT_SECONDS)
                ->env(['ANTHROPIC_API_KEY' => false, 'ANTHROPIC_AUTH_TOKEN' => false])
                ->input($user)
                ->run($this->command($system));
        } catch (ProcessTimedOutException) {
            Log::warning('Claude Code timed out.', ['timeout_seconds' => self::TIMEOUT_SECONDS]);

            throw new AiException(AiException::TOO_SLOW_MESSAGE, retryable: true);
        } finally {
            File::deleteDirectory($workingDirectory);
        }

        $response = json_decode($result->output(), associative: true);

        if (! is_array($response)) {
            Log::warning('Claude Code did not return JSON.', [
                'exit_code' => $result->exitCode(),
                'error_output' => Str::limit($result->errorOutput(), 1000),
            ]);

            throw new AiException;
        }

        $text = (string) ($response['result'] ?? '');

        if ($result->failed()
            || ($response['is_error'] ?? true) === true
            || in_array($response['stop_reason'] ?? null, ['max_tokens', 'refusal'], true)
            || trim($text) === '') {
            $this->failWith($response, $result->exitCode());
        }

        $usage = $response['usage'] ?? [];

        return new AiResult(
            text: $text,
            inputTokens: (int) ($usage['input_tokens'] ?? 0)
                + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                + (int) ($usage['cache_read_input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            costUsdEquivalent: isset($response['total_cost_usd']) ? (float) $response['total_cost_usd'] : null,
            model: $this->model,
            driver: 'claude-code',
        );
    }

    /**
     * The command line, as an array: every element reaches the CLI unchanged, with no shell in between.
     *
     * @return list<string>
     */
    private function command(string $system): array
    {
        return [
            $this->binary,
            '-p',
            '--output-format', 'json',
            '--max-turns', '1',
            '--tools', '',
            '--strict-mcp-config',
            '--setting-sources', '',
            '--no-session-persistence',
            '--model', $this->model,
            '--effort', $this->effort,
            '--system-prompt', $system,
        ];
    }

    /**
     * Log what the CLI reported (never the prompts) and throw the message for the interface.
     *
     * @param  array<string, mixed>  $response
     *
     * @throws AiException always
     */
    private function failWith(array $response, ?int $exitCode): never
    {
        $reason = (string) ($response['result'] ?? '');

        Log::warning('Claude Code reported an error.', [
            'exit_code' => $exitCode,
            'subtype' => $response['subtype'] ?? null,
            'stop_reason' => $response['stop_reason'] ?? null,
            'reason' => Str::limit($reason, 500),
        ]);

        if (Str::contains($reason, ['usage limit', 'hit your limit', 'limit reached', 'rate limit'], ignoreCase: true)) {
            throw new AiException(AiException::LIMIT_MESSAGE, retryable: true);
        }

        throw match ($response['stop_reason'] ?? null) {
            'max_tokens' => new AiException(AiException::TOO_LONG_MESSAGE),
            'refusal' => new AiException(AiException::REFUSED_MESSAGE),
            default => new AiException,
        };
    }
}
