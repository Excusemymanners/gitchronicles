<?php

namespace App\Support;

/**
 * The environment of a child process that handles content from a stranger (git, the code analyzer).
 *
 * A child inherits every variable of the worker, and Laravel has put the whole .env there: APP_KEY,
 * the database and mail passwords, API keys. A process that reads a hostile repository must not see them.
 * Symfony Process removes a variable whose value is false, so onlyKeeping() returns "remove everything the
 * worker has, except these names"; the caller adds the few variables the tool needs on top.
 */
final class ChildEnvironment
{
    /**
     * The harmless variables almost every tool needs: where to find programs, the language and the time zone.
     */
    public const BASIC = ['PATH', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'TZ'];

    /**
     * Variables with these prefixes belong to the application (its keys, passwords and settings).
     */
    private const SECRET_PATTERN = '/\A(APP_|DB_|MAIL_|AWS_|REDIS_|ANTHROPIC_|GITHUB_|GITCHRONICLE_|SESSION_|QUEUE_|CACHE_|PUSHER_|VITE_|SENTRY_|OPENAI_|DATABASE_|DISCOVER_|CONSULTING_|MEDIA_)/';

    /**
     * Every variable of the worker marked as removed, except the ones named in $keep.
     *
     * @param  list<string>  $keep
     * @return array<string, false>
     */
    public static function onlyKeeping(array $keep = self::BASIC): array
    {
        $inherited = array_keys([...getenv(), ...$_ENV, ...$_SERVER]);

        return array_fill_keys(array_diff($inherited, $keep), false);
    }

    /**
     * Only the application's own variables (APP_*, DB_*, MAIL_*, AWS_*, ANTHROPIC_* ...) marked as removed: for a tool that needs the rest
     * (the Claude Code CLI finds its login through HOME), but never the application's keys.
     *
     * @return array<string, false>
     */
    public static function withoutSecrets(): array
    {
        $inherited = array_keys([...getenv(), ...$_ENV]);

        $secrets = array_filter(
            $inherited,
            fn (string $name): bool => preg_match(self::SECRET_PATTERN, $name) === 1,
        );

        return array_fill_keys([...$secrets, 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN'], false);
    }
}
