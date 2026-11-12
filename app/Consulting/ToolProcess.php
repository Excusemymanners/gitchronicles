<?php

namespace App\Consulting;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Runs one tool of the „Consultanță” report on the clone of a repository, which is untrusted code:
 *
 * - with a minimal, explicit environment: every variable of the worker is removed (APP_KEY, the database
 *   password, ANTHROPIC_API_KEY and anything else loaded from .env), and the tool gets only a fixed PATH,
 *   a UTF-8 locale and a HOME inside storage/ (config gitchronicle.consulting.home), so it can neither read
 *   the application's secrets nor write into the home folder of the user who runs the worker;
 * - with a timeout, after which the tool is stopped;
 * - with a cap on its output: past max_output_bytes the tool is stopped, because Laravel keeps the whole
 *   output in memory.
 *
 * The tools only read the clone; none of them runs the repository's code.
 */
final class ToolProcess
{
    /**
     * Where the tools look for the programs they start themselves (Semgrep runs git to list the files).
     */
    public const PATH = '/usr/local/bin:/usr/bin:/bin';

    /**
     * Run a command in $directory and return its result.
     *
     * The output callback counts the bytes while the tool writes them and throws past the cap; Symfony then
     * stops the process (a process object that is destroyed while running is killed). The second check after
     * the run is for the same rule when the process is faked in the tests, where the callback is not called.
     *
     * @param  list<string>  $command
     *
     * @throws ScanToolException when the tool runs out of time or writes too much
     */
    public function run(string $toolName, array $command, string $directory, int $timeoutSeconds): ProcessResult
    {
        $maxBytes = (int) config('gitchronicle.consulting.max_output_bytes');
        $tooLarge = new ScanToolException(ScanToolException::OUTPUT_TOO_LARGE, intdiv($maxBytes, 1024 * 1024));
        $receivedBytes = 0;

        try {
            $result = Process::path($directory)
                ->env(self::environment())
                ->timeout($timeoutSeconds)
                ->run($command, function (string $type, string $chunk) use (&$receivedBytes, $maxBytes, $tooLarge): void {
                    $receivedBytes += strlen($chunk);

                    if ($receivedBytes > $maxBytes) {
                        throw $tooLarge;
                    }
                });
        } catch (ProcessTimedOutException) {
            Log::warning('A consulting tool timed out.', ['tool' => $toolName, 'timeout_seconds' => $timeoutSeconds]);

            throw new ScanToolException(ScanToolException::TIMED_OUT, $timeoutSeconds);
        }

        if (strlen($result->output()) + strlen($result->errorOutput()) > $maxBytes) {
            throw $tooLarge;
        }

        return $result;
    }

    /**
     * The environment of one tool: every inherited variable removed (false removes it, see Symfony Process),
     * then only what the tools need.
     *
     * - SEMGREP_SEND_METRICS=off and SEMGREP_ENABLE_VERSION_CHECK=0 repeat the command's flags, so Semgrep
     *   sends nothing to semgrep.dev even when it is started by hand with these variables;
     * - GIT_CONFIG_NOSYSTEM and GIT_TERMINAL_PROMPT keep the git started by Semgrep from reading this
     *   computer's git settings or waiting for a password.
     *
     * @return array<string, string|false>
     */
    public static function environment(): array
    {
        $home = rtrim((string) config('gitchronicle.consulting.home'), '/').'/home';

        if (! is_dir($home)) {
            mkdir($home, 0755, true);
        }

        $inherited = array_fill_keys(array_keys(getenv() + $_ENV), false);

        return [
            ...$inherited,
            'PATH' => self::PATH,
            'HOME' => $home,
            'XDG_CACHE_HOME' => "{$home}/.cache",
            'XDG_CONFIG_HOME' => "{$home}/.config",
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'SEMGREP_SEND_METRICS' => 'off',
            'SEMGREP_ENABLE_VERSION_CHECK' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }
}
