<?php

namespace App\Consulting\Runtime;

/**
 * Reads the version a manifest asks for ("^8.2", ">=18", "~3.9.1", "8.1.*", "^7.4 || ^8.0", ">=3.8,<4") and
 * returns the LOWEST version it allows. That is the honest reading of a range: the project promises to work
 * on that version, so that is the oldest runtime it can be run on. A pinned version ("20.11.0") is returned as is.
 *
 * Only the numbers matter here, never the operators, except that an upper-bound-only rule ("<9") says nothing
 * about the lowest version and is ignored.
 */
final class VersionConstraint
{
    /**
     * The lowest allowed version and whether the text was a range (true) or one exact version (false).
     * Null when the text holds no usable version ("latest", "lts/*", "*", "$PHP_VERSION").
     *
     * @return array{version: string, is_range: bool}|null
     */
    public static function lowest(string $constraint): ?array
    {
        $constraint = trim(str_replace(['"', "'"], '', $constraint));
        $lowest = null;

        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            $version = self::lowerBound($alternative);

            if ($version !== null && ($lowest === null || version_compare($version, $lowest, '<'))) {
                $lowest = $version;
            }
        }

        if ($lowest === null) {
            return null;
        }

        $isExact = preg_match('/^v?\d+(\.\d+){0,2}$/', $constraint) === 1;

        return ['version' => $lowest, 'is_range' => ! $isExact];
    }

    /**
     * The first lower bound of one alternative: the first number that is not the argument of "<" or "<=".
     */
    private static function lowerBound(string $alternative): ?string
    {
        // Hyphen ranges ("1.2 - 2.0") and comma or space separated rules: look at each rule on its own.
        foreach (preg_split('/[\s,]+(?:-\s+)?/', trim($alternative)) ?: [] as $rule) {
            if ($rule === '' || str_starts_with($rule, '<') || str_starts_with($rule, '!=')) {
                continue;
            }

            if (preg_match('/^(?:[\^~>=v]|~>)*\s*(\d+)(?:\.(\d+|[x*]))?(?:\.(\d+|[x*]))?/i', $rule, $parts) !== 1) {
                continue;
            }

            $numbers = [$parts[1]];

            foreach ([$parts[2] ?? null, $parts[3] ?? null] as $part) {
                if ($part === null || $part === '') {
                    break;
                }

                if (in_array(strtolower($part), ['x', '*'], true)) {
                    $numbers[] = '0';

                    break;
                }

                $numbers[] = $part;
            }

            return implode('.', $numbers);
        }

        return null;
    }
}
