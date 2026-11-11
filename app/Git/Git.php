<?php

namespace App\Git;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * The start of every git command GitChronicle runs: a process that can never stop and wait for
 * someone to type a user name or a password.
 *
 * The clones are blobless (see LocalClone), so even a command that only reads the clone may go to
 * GitHub for a file content it does not have yet. If GitHub then asks for credentials (the repository
 * became private or was deleted), git would wait at a prompt that nobody sees and the worker would hang
 * until its timeout. GIT_TERMINAL_PROMPT=0 and an empty GIT_ASKPASS make git fail at once instead,
 * and the step reports the error.
 */
final class Git
{
    /**
     * The environment of every git process.
     */
    public const ENVIRONMENT = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => ''];

    /**
     * A git process that runs in the given folder (a clone), or in the current one.
     *
     * @param  array<string, string>  $environment  more variables for this one command, added to ENVIRONMENT
     */
    public static function process(?string $path = null, array $environment = []): PendingProcess
    {
        $process = Process::env([...self::ENVIRONMENT, ...$environment]);

        return $path === null ? $process : $process->path($path);
    }
}
