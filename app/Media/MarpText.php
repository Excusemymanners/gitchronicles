<?php

namespace App\Media;

use Illuminate\Support\Str;

/**
 * Makes a text from a repository safe to put into the Markdown of the slides. Such a text is code from
 * the internet: without escaping, a "<style>" could restyle the deck, "---" could start a new slide,
 * "|" could break a table and ":name:" or "$x$" would turn into an emoji picture or a formula.
 *
 * Every value written into the deck goes through text() (or code() for a command in a code block).
 */
final class MarpText
{
    /**
     * A text for a paragraph, a heading, a list item or a table cell: cleaned (RomanianText::clean),
     * cut to $limit characters at a word, "&" and "<" turned into entities, and every character Markdown
     * or Marp gives a meaning to escaped with a backslash.
     */
    public static function text(?string $value, int $limit = 300): string
    {
        $text = Str::limit(RomanianText::clean($value ?? ''), $limit, '…', preserveWords: true);
        $text = str_replace(['&', '<'], ['&amp;', '&lt;'], $text);
        $text = preg_replace('/[\\\\`*_\[\](){}#+!|>~:$]/', '\\\\$0', $text) ?? '';

        // A text that starts with "-" or "1." would become a list.
        return preg_replace(['/\A-/', '/\A(\d+)\./'], ['\\-', '$1\\.'], $text) ?? '';
    }

    /**
     * A one-line command for a fenced code block or for `inline code`, where nothing is interpreted:
     * only a backtick could end the code, so a command with one is not shown (null).
     */
    public static function code(string $value): ?string
    {
        $code = RomanianText::clean($value);

        return $code === '' || str_contains($code, '`') ? null : $code;
    }
}
