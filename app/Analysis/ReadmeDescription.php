<?php

namespace App\Analysis;

use Illuminate\Support\Str;

/**
 * A short description of a project, taken from its README: the first paragraph that is real text.
 *
 * Skipped: headings ("# Title"), images and badges ("![...]", "[![...]"), HTML lines ("<img ...>"),
 * horizontal rules ("---") and code blocks. What remains is turned into plain text:
 * "[Laravel](https://laravel.com)" → "Laravel", "**fast**" → "fast", "`code`" → "code".
 */
final class ReadmeDescription
{
    /**
     * The longest description, in characters; a longer paragraph is cut at a word and ends with "…".
     */
    public const MAX_LENGTH = 300;

    /**
     * The description, or null when the README has no paragraph of text.
     */
    public static function fromMarkdown(string $markdown): ?string
    {
        $paragraphs = preg_split('/\R[ \t]*\R/', trim($markdown)) ?: [];

        foreach ($paragraphs as $paragraph) {
            $text = self::plainText($paragraph);

            if (preg_match('/\p{L}/u', $text) === 1) {
                return Str::limit($text, self::MAX_LENGTH, '…', preserveWords: true);
            }
        }

        return null;
    }

    /**
     * The text of one paragraph, without the lines that are not text and without Markdown syntax.
     */
    private static function plainText(string $paragraph): string
    {
        if (preg_match('/\A\s*(```|~~~|    )/', $paragraph) === 1) {
            return '';
        }

        $lines = array_filter(
            preg_split('/\R/', $paragraph) ?: [],
            fn (string $line): bool => preg_match('/\A\s*(#|!\[|\[!\[|<|([-*_=]\s*){3,}\z)/', $line) !== 1,
        );

        $text = implode(' ', $lines);
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = strip_tags($text);
        $text = str_replace(['**', '__', '`'], '', $text);

        return Str::squish($text);
    }
}
