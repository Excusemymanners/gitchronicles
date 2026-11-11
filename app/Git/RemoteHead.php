<?php

namespace App\Git;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;

/**
 * The newest commit of a repository on GitHub, asked with `git ls-remote <url> HEAD`: one small
 * request, without cloning or fetching anything. The scheduled check (repositories:check-updates)
 * compares it with the last processed commit to decide whether an update is worth starting.
 *
 * Like every git command that talks to GitHub, it cannot ask for a password and cannot find one
 * (see LocalClone), and it has a time limit.
 */
final class RemoteHead
{
    /**
     * After this many seconds the question is abandoned (the answer is then "unknown").
     */
    public const TIMEOUT_SECONDS = 20;

    /**
     * The sha of the remote's HEAD (the tip of its main branch), or null when GitHub did not answer,
     * the repository is gone or private, or it has no commits.
     */
    public static function of(string $url): ?string
    {
        try {
            $result = Git::process()
                ->timeout(self::TIMEOUT_SECONDS)
                ->run(['git', '-c', 'credential.helper=', '-c', 'core.askPass=', 'ls-remote', '--', $url, 'HEAD']);
        } catch (ProcessTimedOutException) {
            Log::warning('git ls-remote timed out.', ['url' => $url]);

            return null;
        }

        // One line: "<sha>\tHEAD".
        if ($result->failed() || preg_match('/\A([0-9a-f]{40})\s+HEAD\b/', $result->output(), $match) !== 1) {
            Log::warning('git ls-remote gave no commit.', ['url' => $url, 'exit_code' => $result->exitCode()]);

            return null;
        }

        return $match[1];
    }
}
