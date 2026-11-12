<?php

namespace App\Git;

use App\Support\ChildEnvironment;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * The start of every git command GitChronicle runs: a process that can never stop and wait for
 * someone to type a user name or a password, and that does nothing a hostile repository could abuse.
 *
 * The clones are blobless (see LocalClone), so even a command that only reads the clone may go to
 * GitHub for a file content it does not have yet. If GitHub then asks for credentials (the repository
 * became private or was deleted), git would wait at a prompt that nobody sees and the worker would hang
 * until its timeout. GIT_TERMINAL_PROMPT=0 and an empty GIT_ASKPASS make git fail at once instead.
 *
 * The repository is written by a stranger, so git also runs in a closed room (environment()):
 * - none of the worker's variables (APP_KEY, passwords, API keys from .env) reach git;
 * - no settings of this computer: GIT_CONFIG_GLOBAL=/dev/null and GIT_CONFIG_NOSYSTEM=1 mean a filter
 *   such as git-lfs configured for the user cannot run on the repository's files (GIT_LFS_SKIP_SMUDGE too);
 * - only the protocols of config gitchronicle.git.protocols (https) may be used, never ssh, ext:: or file:;
 * - no hooks (core.hooksPath=/dev/null) and symbolic links are checked out as plain files
 *   (core.symlinks=false), so a link in the repository cannot point at a file of this server.
 */
final class Git
{
    /**
     * The variables of every git process (the room around git is added by environment()).
     */
    public const ENVIRONMENT = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => ''];

    /**
     * A git process that runs in the given folder (a clone), or in the current one.
     *
     * @param  array<string, string>  $environment  more variables for this one command, added to environment()
     */
    public static function process(?string $path = null, array $environment = []): PendingProcess
    {
        $process = Process::env([...self::environment(), ...$environment]);

        return $path === null ? $process : $process->path($path);
    }

    /**
     * The full environment of a git process: the common variables, the closed room and the git settings
     * given as GIT_CONFIG_* variables (they apply to every command, including `git clone`, without
     * writing anything into the clone).
     *
     * @return array<string, string|false>
     */
    public static function environment(): array
    {
        $settings = [
            'core.hooksPath' => '/dev/null',
            'core.symlinks' => 'false',
            'core.fsmonitor' => 'false',
            'protocol.allow' => 'never',
        ];

        foreach (array_filter(array_map('trim', explode(',', (string) config('gitchronicle.git.protocols')))) as $protocol) {
            $settings["protocol.{$protocol}.allow"] = 'always';
        }

        $variables = [];
        $index = 0;

        foreach ($settings as $key => $value) {
            $variables["GIT_CONFIG_KEY_{$index}"] = $key;
            $variables["GIT_CONFIG_VALUE_{$index}"] = $value;
            $index++;
        }

        return [
            ...ChildEnvironment::onlyKeeping(),
            ...self::ENVIRONMENT,
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_LFS_SKIP_SMUDGE' => '1',
            'GIT_CONFIG_COUNT' => (string) $index,
            ...$variables,
        ];
    }
}
