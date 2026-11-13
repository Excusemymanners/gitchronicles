<?php

namespace App\Documentation;

/**
 * The part of the README that describes the project (its intro, features, motivation, usage, screenshots), chosen
 * from the sections ReadmeShowcase stored, ready to show under "From the project's own README".
 *
 * It is only a choice and a layout, never new text: a section is left out when it is not a description (badges
 * only, contributing, licence, changelog, sponsors, ...) or when the page above already quotes its text word for
 * word; the text of a kept section is the README's own. The Markdown it returns gets its pictures from the app's
 * stored copies only; the page renders it through the same safe Markdown component as every other text.
 */
final class ReadmeDescription
{
    /**
     * Headings that are never a description, even when a describing word appears in them.
     */
    private const NOT_DESCRIPTION = '/\b(contribut|licen[sc]e|changelog|change log|release notes|sponsor|backers?|code of conduct|acknowledg|credits|authors?|funding|security|donat)/iu';

    /**
     * The kept sections.
     *
     * @param  array<string, mixed>  $manifest  the manifest of ReadmeShowcase
     * @param  callable(string, ?int, ?int): string  $imagePath  picture file name ("readme-1.png"), width, height => the path the Markdown image uses
     * @return list<array{heading: ?string, level: int, markdown: string, pictures: list<string>}>
     */
    public static function sections(array $manifest, string $pageMarkdown, callable $imagePath): array
    {
        $pictures = [];

        foreach ($manifest['pictures'] ?? [] as $picture) {
            $pictures[$picture['id']] = $picture;
        }

        $above = self::normalize($pageMarkdown);
        $kept = [];

        foreach ($manifest['sections'] ?? [] as $section) {
            if ($section['heading'] !== null && preg_match(self::NOT_DESCRIPTION, $section['heading']) === 1) {
                continue;
            }

            if ($section['text'] !== '' && self::isQuoted($section['text'], $above)) {
                continue;
            }

            $markdown = $section['text'];

            foreach ($section['pictures'] as $id) {
                if (! isset($pictures[$id])) {
                    continue;
                }

                $picture = $pictures[$id];
                $markdown .= ($markdown === '' ? '' : "\n\n").'!['.str_replace(['[', ']', "\n"], ['(', ')', ' '], $picture['alt']).']('.$imagePath($picture['file'], $picture['width'], $picture['height']).')';
            }

            if (trim($markdown) !== '') {
                $kept[] = [
                    'heading' => $section['heading'],
                    'level' => $section['level'],
                    'markdown' => $markdown,
                    'pictures' => array_values(array_filter($section['pictures'], fn (string $id): bool => isset($pictures[$id]))),
                ];
            }
        }

        return $kept;
    }

    /**
     * Whether every paragraph of the section already appears in the page above (or, for a long one that the page
     * shortened, its first words do).
     */
    private static function isQuoted(string $text, string $above): bool
    {
        $decisive = 0;

        foreach (preg_split('/\n{2,}/', $text) ?: [] as $paragraph) {
            $paragraph = self::normalize($paragraph);

            // A very short paragraph ("Fast.") appears in any page by chance, so it decides nothing.
            if (mb_strlen($paragraph) < 30) {
                continue;
            }

            $decisive++;

            if (! str_contains($above, mb_substr($paragraph, 0, 100))) {
                return false;
            }
        }

        return $decisive > 0;
    }

    /**
     * Lower case, without link addresses and Markdown signs, words separated by one space.
     */
    private static function normalize(string $markdown): string
    {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $markdown) ?? '';
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)) ?? '';

        return trim($text);
    }
}
