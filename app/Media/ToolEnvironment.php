<?php

namespace App\Media;

use App\Support\ChildEnvironment;

/**
 * The environment variables of the Node tools that drive Chrome (HyperFrames, Marp, the diagram renderer).
 *
 * - HOME and the XDG folders point into storage/app/hyperframes (config gitchronicle.media.hyperframes_home):
 *   whatever the tools or Chrome keep (caches, settings, a profile) stays in the project, never in the
 *   home folder of the user who runs the worker. TMPDIR stays the system's one: Chrome puts a socket in its
 *   temporary profile, and a Unix socket path may not be longer than 108 bytes (a folder deep inside the
 *   project is too long); Chrome deletes that profile when it closes;
 * - HyperFrames gets its cache and config folders there too, with telemetry, the update check and every
 *   automatic download turned off, and the browser and FFmpeg of this computer (config gitchronicle.media);
 * - Marp and Puppeteer get the same browser through CHROME_PATH.
 */
final class ToolEnvironment
{
    /**
     * Whether the tools of renderers/ were installed (composer media:install).
     */
    public static function isInstalled(): bool
    {
        $renderers = rtrim((string) config('gitchronicle.media.renderers_path'), '/');

        return is_file("{$renderers}/node_modules/hyperframes/bin/hyperframes.mjs")
            && is_file("{$renderers}/node_modules/@marp-team/marp-cli/marp-cli.js");
    }

    /**
     * The variables of the worker that a tool may keep when it must not see the others (isolated()).
     */
    private const KEPT_VARIABLES = ['PATH', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'TZ'];

    /**
     * The variables for a tool that opens pages from the internet (the GitHub page capture): the ones of
     * variables(), and every other variable of the worker removed (false = not passed to the child), so
     * the application's keys and passwords from .env never reach the browser.
     *
     * @return array<string, string|false>
     */
    public static function isolated(): array
    {
        $inherited = array_keys([...getenv(), ...$_ENV]);
        $removed = array_fill_keys(array_diff($inherited, self::KEPT_VARIABLES), false);

        return [...$removed, ...self::variables()];
    }

    /**
     * The variables for one run of a tool.
     *
     * @return array<string, string|false>
     */
    public static function variables(): array
    {
        $root = rtrim((string) config('gitchronicle.media.hyperframes_home'), '/');
        $home = "{$root}/home";

        foreach ([$home, "{$root}/cache", "{$root}/config"] as $folder) {
            if (! is_dir($folder)) {
                mkdir($folder, 0755, true);
            }
        }

        return [
            // The tools show content from a stranger's repository: the application's keys and passwords are removed.
            ...ChildEnvironment::withoutSecrets(),
            'HOME' => $home,
            'XDG_CONFIG_HOME' => "{$home}/.config",
            'XDG_CACHE_HOME' => "{$home}/.cache",
            'XDG_STATE_HOME' => "{$home}/.local/state",
            'XDG_DATA_HOME' => "{$home}/.local/share",
            'XDG_RUNTIME_DIR' => false,

            'HYPERFRAMES_CACHE_DIR' => "{$root}/cache",
            'HYPERFRAMES_CONFIG_DIR' => "{$root}/config",
            'HYPERFRAMES_NO_TELEMETRY' => '1',
            'DO_NOT_TRACK' => '1',
            'HYPERFRAMES_NO_UPDATE_CHECK' => '1',
            'HYPERFRAMES_NO_AUTO_INSTALL' => '1',
            'HYPERFRAMES_NO_OPTIONAL_INSTALL' => '1',
            'HYPERFRAMES_SKIP_SKILLS' => '1',
            'HYPERFRAMES_BROWSER_PATH' => (string) config('gitchronicle.media.browser_path'),
            'HYPERFRAMES_FFMPEG_PATH' => (string) config('gitchronicle.media.ffmpeg_path'),
            'HYPERFRAMES_FFPROBE_PATH' => (string) config('gitchronicle.media.ffprobe_path'),

            'CHROME_PATH' => (string) config('gitchronicle.media.browser_path'),
            'PUPPETEER_SKIP_DOWNLOAD' => '1',
        ];
    }
}
