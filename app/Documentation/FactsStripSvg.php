<?php

namespace App\Documentation;

/**
 * The languages bar and the key numbers of a repository as one small SVG picture, drawn from the numbers of
 * DocumentVisuals::facts() (never invented). It has its own light background so it reads on a light and on a dark
 * README page, and every text in it is escaped.
 */
final class FactsStripSvg
{
    /**
     * Shades of one blue for the segments of the bar, largest language first.
     */
    private const SHADES = ['#1f4e8c', '#2f6db5', '#4a8bd0', '#6fa5de', '#94bde8', '#b8d3f0'];

    /**
     * Draw the strip. $labels: "files", "lines", "modules", "commits" and "languages" as words, already translated.
     *
     * @param  array{languages: list<array{name: string, share: float}>, files: int, lines: int, modules: int, commits: int}  $facts
     * @param  array{files: string, lines: string, modules: string, commits: string}  $labels
     */
    public static function draw(array $facts, array $labels): string
    {
        $width = 800;
        $x = 24;
        $barWidth = $width - 48;
        $bar = '';
        $legend = '';
        $cursor = 0.0;

        foreach ($facts['languages'] as $index => $language) {
            $segment = max(2.0, $barWidth * $language['share']);
            $color = self::SHADES[$index % count(self::SHADES)];
            $bar .= sprintf('<rect x="%.1f" y="92" width="%.1f" height="12" fill="%s"/>', $x + $cursor, $segment, $color);
            $cursor += $segment;

            $legendX = 24 + ($index % 3) * 250;
            $legendY = 132 + intdiv($index, 3) * 22;
            $legend .= sprintf(
                '<rect x="%d" y="%d" width="10" height="10" rx="2" fill="%s"/><text x="%d" y="%d" font-size="13" fill="#24292f">%s %s</text>',
                $legendX, $legendY - 9, $color, $legendX + 16, $legendY, self::escape($language['name']), self::percent($language['share']),
            );
        }

        $numbers = '';

        foreach ([[$labels['files'], $facts['files']], [$labels['lines'], $facts['lines']], [$labels['modules'], $facts['modules']], [$labels['commits'], $facts['commits']]] as $index => [$label, $value]) {
            $numbers .= sprintf(
                '<text x="%d" y="46" font-size="24" font-weight="700" fill="#24292f">%s</text><text x="%d" y="68" font-size="13" fill="#57606a">%s</text>',
                24 + $index * 190, number_format($value), 24 + $index * 190, self::escape($label),
            );
        }

        $height = 150 + intdiv(max(0, count($facts['languages']) - 1), 3) * 22;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" font-family="Helvetica, Arial, sans-serif"><rect x="0.5" y="0.5" width="%d" height="%d" rx="10" fill="#ffffff" stroke="#d0d7de"/>%s<clipPath id="bar"><rect x="24" y="92" width="%d" height="12" rx="6"/></clipPath><g clip-path="url(#bar)">%s</g>%s</svg>',
            $width, $height, $width, $height, $width - 1, $height - 1, $numbers, $barWidth, $bar, $legend,
        );
    }

    private static function percent(float $share): string
    {
        return $share >= 0.1 ? round($share * 100).'%' : number_format($share * 100, 1).'%';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
