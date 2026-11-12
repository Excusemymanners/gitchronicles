<?php

namespace App\Media\Slides;

use App\Media\RomanianText;
use Illuminate\Support\Str;

/**
 * Takes the pieces a slide needs out of the Markdown of a documentation page: its "## " sections,
 * the first sentences, the list items, the code blocks. Nothing is invented; a missing piece is null or [].
 */
final class DocText
{
    /**
     * The "## " sections of a page: lower-cased heading => the text under it.
     *
     * @return array<string, string>
     */
    public static function sections(string $markdown): array
    {
        $sections = [];
        $heading = null;
        $inCode = false;

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/\A\s*(```|~~~)/', $line) === 1) {
                $inCode = ! $inCode;
            }

            if (! $inCode && preg_match('/\A##\s+(.+?)\s*#*\z/u', $line, $match) === 1) {
                $heading = mb_strtolower(trim($match[1]));
                $sections[$heading] = '';

                continue;
            }

            if ($heading !== null) {
                $sections[$heading] .= $line."\n";
            }
        }

        return array_map('trim', $sections);
    }

    /**
     * The first section whose heading matches the pattern.
     *
     * @param  array<string, string>  $sections
     */
    public static function find(array $sections, string $pattern): ?string
    {
        foreach ($sections as $heading => $text) {
            if (preg_match($pattern, (string) $heading) === 1 && trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Markdown as plain text: links keep their words, emphasis and HTML go away, backticks stay (code is shown as code).
     */
    public static function plain(string $markdown): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $markdown) ?? '';
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? '';
        $text = preg_replace('/<[^>]+>/', '', $text) ?? '';
        $text = preg_replace('/(\*\*|__|\*|(?<!\w)_|_(?!\w))/', '', $text) ?? '';
        $text = preg_replace('/\A\s*>\s?/m', '', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', RomanianText::clean($text)) ?? '');
    }

    /**
     * The sentences of the prose of a text (lists, tables and code blocks left out).
     *
     * @return list<string>
     */
    public static function sentences(string $markdown): array
    {
        $prose = [];
        $inCode = false;

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/\A\s*(```|~~~)/', $line) === 1) {
                $inCode = ! $inCode;

                continue;
            }

            if ($inCode || preg_match('/\A\s*([-*+]\s|\d+[.)]\s|\||#|>|_This page)/u', $line) === 1) {
                continue;
            }

            $prose[] = $line;
        }

        $text = self::plain(implode(' ', $prose));

        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map(fn (string $sentence): string => self::withoutLeadIn(trim($sentence)), preg_split('/(?<=[.!?])\s+(?=[A-Z0-9`"„(])/u', $text) ?: [])));
    }

    /**
     * The items of the bullet or numbered lists of a text, as Markdown.
     *
     * @return list<string>
     */
    public static function items(string $markdown): array
    {
        preg_match_all('/^[ \t]{0,3}(?:[-*+]|\d+[.)])[ \t]+(.+)$/m', $markdown, $matches);

        return array_values(array_filter(array_map('trim', $matches[1])));
    }

    /**
     * The lines of the fenced code blocks of a text that are shell commands (a leading "$ " is removed).
     *
     * @return list<string>
     */
    public static function commands(string $markdown): array
    {
        preg_match_all('/^[ \t]*(```|~~~)[^\n]*\n(.*?)^[ \t]*\1/ms', $markdown, $blocks);
        $commands = [];

        foreach ($blocks[2] as $block) {
            foreach (preg_split('/\R/u', $block) ?: [] as $line) {
                $line = trim(preg_replace('/\A\s*[$>]\s+/', '', $line) ?? '');

                if ($line !== '' && ! str_starts_with($line, '#') && ! str_starts_with($line, '//')) {
                    $commands[] = $line;
                }
            }
        }

        return $commands;
    }

    /**
     * The rows of the first Markdown table of a text: each row is the list of its cells as plain text.
     *
     * @return list<list<string>>
     */
    public static function tableRows(string $markdown): array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] !== '|') {
                if ($rows !== []) {
                    break;
                }

                continue;
            }

            if (preg_match('/\A\|[\s:|-]+\|?\z/', $line) === 1) {
                continue;
            }

            $rows[] = array_map(fn (string $cell): string => self::plain($cell), explode('|', trim($line, '|')));
        }

        return array_slice($rows, 1);
    }

    /**
     * A text for a small place on a slide, shortened only at a boundary of the text itself: a text that fits
     * stays; a longer one becomes its first sentence; when that sentence is still far too long it ends at
     * the last clause (comma, semicolon, dash) that fits. Never an ellipsis: a long text is shown whole,
     * in smaller type, by the layout.
     */
    public static function short(string $text, int $limit): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return rtrim($text, '.');
        }

        $first = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9`"„(])/u', $text)[0] ?? $text;

        if (mb_strlen($first) <= $limit * 1.5) {
            return rtrim($first, '.');
        }

        $best = null;

        foreach ([', ', '; ', ' — ', ' – ', ': '] as $separator) {
            $position = mb_strrpos(mb_substr($first, 0, $limit), $separator);

            if ($position !== false && $position >= 25 && ($best === null || $position > $best)) {
                $best = $position;
            }
        }

        return rtrim($best === null ? $first : mb_substr($first, 0, $best), '.,;:—– ');
    }

    /**
     * A sentence without the words that only say where it was read: "According to README.md, X" becomes "X".
     */
    public static function withoutLeadIn(string $sentence): string
    {
        $pattern = '/\A(according to|based on|as stated in|as described in|from|per|conform|potrivit|în conformitate cu|după)\s+(the\s+)?[`\[]*(README(\.md|-ul)?|readme|the repository\'s README)[`\]]*\s*[,:]\s*/iu';
        $stripped = preg_replace($pattern, '', $sentence) ?? $sentence;

        return $stripped === $sentence ? $sentence : mb_strtoupper(mb_substr($stripped, 0, 1)).mb_substr($stripped, 1);
    }

    /**
     * A sentence shortened to a headline: it stops at the first comma, colon or dash when the sentence is
     * longer than $limit characters, then at a word boundary.
     */
    public static function headline(string $sentence, int $limit = 80): string
    {
        $sentence = rtrim(trim($sentence), '.');

        if (mb_strlen($sentence) <= $limit) {
            return $sentence;
        }

        foreach ([', ', ': ', ' — ', ' – ', '; '] as $separator) {
            $position = mb_strpos($sentence, $separator);

            if ($position !== false && $position >= 20 && $position <= $limit) {
                return mb_substr($sentence, 0, $position);
            }
        }

        $cut = rtrim(Str::limit($sentence, $limit, '', preserveWords: true), ',;:—- ');

        // A headline must not end on a word that needs the next one.
        return rtrim(preg_replace('/\s+(and|or|of|the|with|to|a|an|in|for|by|on|că|și|de|cu|la|din)\z/iu', '', $cut) ?? $cut, ',;:—- ');
    }
}
