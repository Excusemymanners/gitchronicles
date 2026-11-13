<?php

namespace App\Documentation;

use Illuminate\Support\Str;

/**
 * Reads a README as text and finds what a documentation page can show of it: the pictures (Markdown
 * `![alt](src)`, linked pictures, HTML `<img>`) with the heading they appear under, and the sections that
 * describe the project (the intro, features, motivation, usage, screenshots) with the pictures in each.
 *
 * Nothing is downloaded or read from disk here: this class only finds sources. Badges (shields, build
 * status), tracking pixels and pictures an HTML tag declares smaller than the minimum are skipped; ReadmeShowcase
 * then fetches the rest safely. Lines inside code blocks are never read.
 */
final class ReadmeImages
{
    /**
     * Addresses of images that are badges or counters, not pictures of the project.
     */
    private const BADGE_PATTERN = '#shields\.io|img\.shields|badge|travis-ci|codecov|coveralls|circleci|github\.com/[^/]+/[^/]+/(?:actions|workflows)|sonarcloud|badgen\.net|img\.youtube\.com|visitor-badge|hits\.seeyoufarm|api\.star-history|opencollective\.com/[^ ]*/badge|contrib\.rocks|capsule-render#i';

    /**
     * Headings of the sections that describe a project (the intro, which has no heading, always counts).
     */
    private const DESCRIBING_HEADINGS = '/\A(about|overview|introduction|what is|what\'s|features?|highlights?|key features|why\b|motivation|the problem|background|how it works|usage|quick ?start|getting started|demo|screenshots?|preview|gallery|examples?|showcase|architecture|design)/iu';

    /**
     * A section's text is cut at this many characters, at the end of a paragraph when possible.
     */
    public const MAX_SECTION_CHARACTERS = 1200;

    /**
     * At most this many sections are kept.
     */
    public const MAX_SECTIONS = 8;

    /**
     * The pictures of the README in the order they appear.
     *
     * @return list<array{source: string, alt: string, heading: ?string, position: int}>
     */
    public static function pictures(string $markdown, int $minimumPixels = 120): array
    {
        $found = [];
        $heading = null;
        $position = 0;

        foreach (self::lines($markdown) as $line) {
            if ($line['heading'] !== null) {
                $heading = $line['heading'];
            }

            foreach (self::imagesIn($line['text'], $minimumPixels) as $image) {
                $found[] = [...$image, 'heading' => $heading, 'position' => $position++];
            }
        }

        return $found;
    }

    /**
     * The describing sections: heading (null for the intro), level, plain text without pictures, and the
     * sources of the pictures inside it.
     *
     * @return list<array{heading: ?string, level: int, text: string, sources: list<string>}>
     */
    public static function sections(string $markdown, int $minimumPixels = 120): array
    {
        $sections = [];
        $current = ['heading' => null, 'level' => 1, 'lines' => [], 'sources' => []];

        foreach (self::lines($markdown) as $line) {
            if ($line['heading'] !== null) {
                $sections[] = $current;
                $current = ['heading' => $line['heading'], 'level' => $line['level'], 'lines' => [], 'sources' => []];

                continue;
            }

            foreach (self::imagesIn($line['text'], $minimumPixels) as $image) {
                $current['sources'][] = $image['source'];
            }

            $current['lines'][] = $line['text'];
        }

        $sections[] = $current;
        $kept = [];

        foreach ($sections as $section) {
            // The title of the README ("# Name") is the first heading; its own section is the intro.
            $isIntro = $section['heading'] === null || ($kept === [] && $section['level'] === 1);

            if (! $isIntro && preg_match(self::DESCRIBING_HEADINGS, $section['heading']) !== 1) {
                continue;
            }

            $text = self::plainText(implode("\n", $section['lines']));

            if ($text === '' && $section['sources'] === []) {
                continue;
            }

            $kept[] = [
                'heading' => $section['heading'],
                'level' => $section['level'],
                'text' => $text,
                'sources' => array_values(array_unique($section['sources'])),
            ];

            if (count($kept) >= self::MAX_SECTIONS) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Lines outside code blocks, each with the heading it is (text of the heading and level) or null.
     *
     * @return list<array{text: string, heading: ?string, level: int}>
     */
    private static function lines(string $markdown): array
    {
        $lines = [];
        $inCode = false;

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/\A\s{0,3}(```|~~~)/', $line) === 1) {
                $inCode = ! $inCode;

                continue;
            }

            if ($inCode) {
                continue;
            }

            if (preg_match('/\A\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*\z/u', $line, $heading) === 1) {
                $lines[] = ['text' => '', 'heading' => trim(self::plainText($heading[2])), 'level' => strlen($heading[1])];

                continue;
            }

            $lines[] = ['text' => $line, 'heading' => null, 'level' => 0];
        }

        return $lines;
    }

    /**
     * The pictures of one line: `![alt](src "title")` and `<img src alt width height>`.
     *
     * @return list<array{source: string, alt: string}>
     */
    private static function imagesIn(string $line, int $minimumPixels): array
    {
        $images = [];

        preg_match_all('/!\[([^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/u', $line, $markdown, PREG_SET_ORDER);

        foreach ($markdown as $match) {
            $images[] = ['source' => $match[2], 'alt' => $match[1], 'width' => null, 'height' => null];
        }

        preg_match_all('/<img\b[^>]*>/i', $line, $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $source) !== 1) {
                continue;
            }

            $alt = preg_match('/\balt\s*=\s*["\']([^"\']*)["\']/i', $tag, $altMatch) === 1 ? $altMatch[1] : '';
            $width = preg_match('/\bwidth\s*=\s*["\']?(\d+)(?!%)/i', $tag, $size) === 1 ? (int) $size[1] : null;
            $height = preg_match('/\bheight\s*=\s*["\']?(\d+)(?!%)/i', $tag, $size) === 1 ? (int) $size[1] : null;

            $images[] = ['source' => html_entity_decode($source[1]), 'alt' => html_entity_decode($alt), 'width' => $width, 'height' => $height];
        }

        $kept = [];

        foreach ($images as $image) {
            $source = trim($image['source']);
            $declaredSmall = ($image['width'] !== null && $image['width'] < $minimumPixels && ($image['height'] === null || $image['height'] < $minimumPixels))
                || ($image['height'] !== null && $image['height'] < $minimumPixels && $image['width'] === null);

            if ($source === '' || str_starts_with($source, 'data:') || str_starts_with($source, '//') || preg_match('#\A(?!https?://)[a-z][a-z0-9+.-]*:#i', $source) === 1
                || preg_match(self::BADGE_PATTERN, $source) === 1 || preg_match(self::BADGE_PATTERN, $image['alt']) === 1 || $declaredSmall) {
                continue;
            }

            $kept[] = ['source' => $source, 'alt' => trim(preg_replace('/\s+/', ' ', $image['alt']) ?? '')];
        }

        return $kept;
    }

    /**
     * Text without pictures, HTML tags and badges; links keep their words. Paragraphs stay separated.
     */
    private static function plainText(string $markdown): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $markdown) ?? '';
        $text = preg_replace('/<!--.*?-->/s', '', $text) ?? '';
        $text = preg_replace('#<(script|style)\b.*?</\1>#is', '', $text) ?? '';
        $text = preg_replace('/<[^>]+>/', '', $text) ?? '';
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? '';
        $text = preg_replace('/\[([^\]]*)\]\[[^\]]*\]/u', '$1', $text) ?? '';
        $text = html_entity_decode($text);
        $paragraphs = [];

        foreach (preg_split('/\R{2,}/', $text) ?: [] as $paragraph) {
            $lines = array_map('rtrim', preg_split('/\R/', trim($paragraph)) ?: []);
            $paragraph = trim(implode("\n", array_filter($lines, fn (string $line): bool => trim($line) !== '')));

            if ($paragraph !== '' && ! str_starts_with($paragraph, '|---')) {
                $paragraphs[] = $paragraph;
            }
        }

        $result = '';

        foreach ($paragraphs as $paragraph) {
            if ($result !== '' && mb_strlen($result) + mb_strlen($paragraph) > self::MAX_SECTION_CHARACTERS) {
                break;
            }

            $result .= ($result === '' ? '' : "\n\n").$paragraph;
        }

        return Str::limit($result, self::MAX_SECTION_CHARACTERS, '…');
    }
}
