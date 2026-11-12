<?php

namespace App\Media\Slides;

/**
 * The colours of one deck, derived from ONE accent colour of the repository (its logo, its avatar or its
 * main language). The deck is light (paper, ink) with a dark "brand" colour for the title and the closing
 * slide. Every text colour is moved until its contrast with the background it is used on is at least 4.5:1
 * (WCAG AA for normal text), and contrast() lets a test prove it.
 */
final class SlidePalette
{
    public const MINIMUM_CONTRAST = 4.5;

    /**
     * The bounds of the palette (saturation and lightness are 0-1): the page tints stay pale, the accent is
     * a medium colour, and the title background is dark and calm.
     */
    public const TINT_SATURATION_MAX = 0.25;

    public const ACCENT_SATURATION_MIN = 0.3;

    public const ACCENT_SATURATION_MAX = 0.5;

    public const ACCENT_LIGHTNESS = 0.44;

    public const BRAND_SATURATION_MIN = 0.22;

    public const BRAND_SATURATION_MAX = 0.45;

    public const BRAND_LIGHTNESS = 0.22;

    /**
     * The tokens of a deck: each one is a "#rrggbb" colour. The pairs that must keep the contrast are
     * listed in textPairs().
     *
     * @return array{paper: string, surface: string, line: string, ink: string, muted: string, accent: string, onAccent: string, accentInk: string, brand: string, onBrand: string, onBrandMuted: string, brandHighlight: string}
     */
    public static function fromAccent(string $accent): array
    {
        [$hue, $saturation] = self::hsl(self::normalize($accent));
        // The hue is the repository's; the saturation and lightness of every role stay inside calm bounds,
        // so a loud logo colour never makes a loud deck (see the BOUNDS constants).
        $tint = min($saturation, self::TINT_SATURATION_MAX);
        $strong = max(self::ACCENT_SATURATION_MIN, min($saturation, self::ACCENT_SATURATION_MAX));
        $calm = max(self::BRAND_SATURATION_MIN, min($saturation * 0.7, self::BRAND_SATURATION_MAX));

        $paper = self::fromHsl($hue, $tint, 0.985);
        $surface = self::fromHsl($hue, $tint, 0.94);
        $ink = self::fromHsl($hue, min($tint, 0.35), 0.1);

        $accentFill = self::fromHsl($hue, $strong, self::ACCENT_LIGHTNESS);
        $brand = self::darkEnough($hue, $calm, self::BRAND_LIGHTNESS, '#ffffff', 8.0);

        return [
            'paper' => $paper,
            'surface' => $surface,
            'line' => self::fromHsl($hue, $tint, 0.85),
            'ink' => $ink,
            'muted' => self::darkEnough($hue, min($tint, 0.2), 0.4, $surface, self::MINIMUM_CONTRAST + 0.5),
            'accent' => $accentFill,
            'onAccent' => self::contrast('#ffffff', $accentFill) >= self::contrast('#000000', $accentFill) ? '#ffffff' : '#000000',
            'accentInk' => self::darkEnough($hue, $strong, self::ACCENT_LIGHTNESS, $surface, self::MINIMUM_CONTRAST + 0.2),
            'brand' => $brand,
            'onBrand' => '#ffffff',
            'onBrandMuted' => self::lightEnough($hue, 0.55, 0.86, $brand, self::MINIMUM_CONTRAST + 1.5),
            'brandHighlight' => self::lightEnough($hue, min(0.7, max(0.45, $strong + 0.1)), 0.72, $brand, self::MINIMUM_CONTRAST + 1.0),
        ];
    }

    /**
     * Every pair of (text colour, background) that must reach MINIMUM_CONTRAST.
     *
     * @param  array<string, string>  $tokens  fromAccent()
     * @return list<array{string, string, string}> [text token, background token, description]
     */
    public static function textPairs(): array
    {
        return [
            ['ink', 'paper', 'text on the page'],
            ['ink', 'surface', 'text on a card'],
            ['muted', 'paper', 'secondary text on the page'],
            ['muted', 'surface', 'secondary text on a card'],
            ['accentInk', 'paper', 'accent text on the page'],
            ['accentInk', 'surface', 'accent text on a card'],
            ['onAccent', 'accent', 'text on an accent fill'],
            ['onBrand', 'brand', 'text on the title slide'],
            ['onBrandMuted', 'brand', 'secondary text on the title slide'],
            ['brandHighlight', 'brand', 'highlight on the title slide'],
        ];
    }

    /**
     * The WCAG contrast ratio of two "#rrggbb" colours, from 1 to 21.
     */
    public static function contrast(string $first, string $second): float
    {
        $one = self::luminance($first);
        $two = self::luminance($second);

        return (max($one, $two) + 0.05) / (min($one, $two) + 0.05);
    }

    /**
     * Turn "#abc", "ABCDEF" or "#AbCdEf" into "#rrggbb"; anything else is black.
     */
    public static function normalize(string $color): string
    {
        $color = ltrim(strtolower(trim($color)), '#');

        if (preg_match('/\A[0-9a-f]{3}\z/', $color) === 1) {
            $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
        }

        return preg_match('/\A[0-9a-f]{6}\z/', $color) === 1 ? "#{$color}" : '#000000';
    }

    /**
     * The hue (0-360), saturation and lightness (0-1) of a colour.
     *
     * @return array{float, float, float}
     */
    public static function hsl(string $color): array
    {
        [$red, $green, $blue] = array_map(fn (int $channel): float => $channel / 255, self::rgb($color));
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta < 0.00001) {
            return [0.0, 0.0, $lightness];
        }

        $saturation = $delta / (1 - abs(2 * $lightness - 1));
        $hue = match ($max) {
            $red => fmod(($green - $blue) / $delta, 6),
            $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        } * 60;

        return [$hue < 0 ? $hue + 360 : $hue, $saturation, $lightness];
    }

    public static function fromHsl(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $chroma / 2;

        [$red, $green, $blue] = match (true) {
            $hue < 60 => [$chroma, $x, 0],
            $hue < 120 => [$x, $chroma, 0],
            $hue < 180 => [0, $chroma, $x],
            $hue < 240 => [0, $x, $chroma],
            $hue < 300 => [$x, 0, $chroma],
            default => [$chroma, 0, $x],
        };

        return sprintf('#%02x%02x%02x', round(($red + $m) * 255), round(($green + $m) * 255), round(($blue + $m) * 255));
    }

    /**
     * Start at the given lightness and get darker until the colour has the contrast on the background.
     */
    private static function darkEnough(float $hue, float $saturation, float $lightness, string $background, float $contrast): string
    {
        for ($step = 0; $step <= 40; $step++) {
            $color = self::fromHsl($hue, $saturation, max(0.0, $lightness - $step * 0.01));

            if (self::contrast($color, $background) >= $contrast) {
                return $color;
            }
        }

        return '#000000';
    }

    /**
     * Start at the given lightness and get lighter until the colour has the contrast on the background.
     */
    private static function lightEnough(float $hue, float $saturation, float $lightness, string $background, float $contrast): string
    {
        for ($step = 0; $step <= 14; $step++) {
            $color = self::fromHsl($hue, $saturation, min(1.0, $lightness + $step * 0.01));

            if (self::contrast($color, $background) >= $contrast) {
                return $color;
            }
        }

        return '#ffffff';
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $color): array
    {
        return [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];
    }

    private static function luminance(string $color): float
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::rgb(self::normalize($color)));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
