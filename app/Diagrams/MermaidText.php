<?php

namespace App\Diagrams;

use Illuminate\Support\Str;

/**
 * Makes text from a repository (folder names, class names, the README) safe inside a Mermaid label.
 *
 * Mermaid labels are written between double quotes, and Mermaid understands "entity codes"
 * like #quot; for characters that would end or change the label. So:
 *
 * - newlines and other control characters become spaces (a label is built line by line);
 * - #, ", <, >, &, ` and \ are written as entity codes (#35; #quot; #lt; #gt; #amp; #96; #92;),
 *   so the text can never close the label, start an HTML tag or a Markdown string;
 * - brackets, braces, diacritics and emoji stay as they are: inside quotes they are plain text.
 *
 * The only HTML in our labels is added by label() itself: <b> for the title and <br> between lines.
 */
final class MermaidText
{
    /**
     * A longer piece of text is shortened, so one long name cannot stretch a whole diagram.
     */
    public const MAX_LINE_LENGTH = 90;

    /**
     * Escape one line of text for a quoted Mermaid label.
     */
    public static function escape(string $text, int $maxLength = self::MAX_LINE_LENGTH): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\p{Cc}\s]+/u', ' ', $text) ?? '';
        $text = Str::limit(trim($text), $maxLength, '…');

        // strtr() replaces every character once, so the "#" of a new entity code is not escaped again.
        // The backslash too: in the `id@{ label: "…" }` form Mermaid reads the label as a YAML string, where
        // \x3c would turn back into "<" after this escaping (security audit XSS-INJECTION-001).
        return strtr($text, [
            '\\' => '#92;',
            '#' => '#35;',
            '"' => '#quot;',
            '<' => '#lt;',
            '>' => '#gt;',
            '&' => '#amp;',
            '`' => '#96;',
        ]);
    }

    /**
     * A label with a bold title and, below it, one line for each detail.
     * Without details it is just the escaped title, not bold. A detail line may be longer than
     * MAX_LINE_LENGTH when $detailLength says so (Mermaid wraps it inside the box).
     *
     * @param  list<string>  $details
     */
    public static function label(string $title, array $details = [], int $detailLength = self::MAX_LINE_LENGTH): string
    {
        if ($details === []) {
            return self::escape($title);
        }

        $lines = array_map(fn (string $detail): string => self::escape($detail, $detailLength), $details);

        return '<b>'.self::escape($title).'</b><br>'.implode('<br>', $lines);
    }
}
