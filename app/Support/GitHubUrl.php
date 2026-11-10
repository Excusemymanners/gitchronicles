<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Recognizes GitHub repository links and brings them to one canonical form:
 * https://github.com/{owner}/{name}, in lowercase.
 *
 * The strict rules also protect the future `git clone` call: the URL always
 * starts with "https://" (never with "-") and the name can never be "." or "..".
 */
final class GitHubUrl
{
    /**
     * A canonical GitHub repository URL, usable in a "regex:" validation rule.
     *
     * - owner: letters, digits and single hyphens, 1–39 characters,
     *   cannot start or end with "-" (GitHub's username rules);
     * - name: letters, digits, ".", "_" and "-", 1–100 characters, not "." or "..".
     *
     * \A and \z mean the very start and end of the text ($ would also accept a trailing newline).
     */
    public const PATTERN = '~\Ahttps://github\.com/(?<owner>[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){0,38})/(?<name>(?!\.{1,2}\z)[a-z0-9._-]{1,100})\z~';

    /**
     * The loose forms people paste: with or without "http(s)://" and "www.",
     * with a ".git" suffix, a trailing slash, extra path, ?query or #hash.
     */
    private const LOOSE_PATTERN = '~\A(?:https?://)?(?:www\.)?github\.com/(?<owner>[^/?#]+)/(?<name>[^/?#]+?)(?:\.git)?(?:[/?#].*)?\z~i';

    /**
     * Turn a pasted GitHub link into its canonical form.
     *
     * Input that is not a valid GitHub repository link is returned trimmed
     * but otherwise unchanged, so validation can reject it.
     */
    public static function normalize(string $input): string
    {
        $input = trim($input);

        if (preg_match(self::LOOSE_PATTERN, $input, $matches) !== 1) {
            return $input;
        }

        $canonical = Str::lower("https://github.com/{$matches['owner']}/{$matches['name']}");

        return self::parse($canonical) === null ? $input : $canonical;
    }

    /**
     * Split a canonical URL into owner and name, or return null if it is not canonical.
     *
     * @return array{owner: string, name: string}|null
     */
    public static function parse(string $url): ?array
    {
        if (preg_match(self::PATTERN, $url, $matches) !== 1) {
            return null;
        }

        return [
            'owner' => $matches['owner'],
            'name' => $matches['name'],
        ];
    }
}
