<?php

namespace App\Media\Slides;

/**
 * The colour an image is "about": the most common vivid colour of a logo or an avatar. Transparent,
 * white, black and grey pixels are the background of most logos, so they do not count.
 */
final class DominantColor
{
    private const SIZE = 48;

    private const HUE_GROUPS = 24;

    /**
     * The dominant colour of an image as "#rrggbb", from its pixels (renderers/slides/pixels.mjs makes them
     * with Chrome: PHP may have no image library, and Chrome also reads SVG), or null when the image has no
     * vivid colour (a black and white logo).
     *
     * @param  list<int>  $rgba  the 48×48 pixels: red, green, blue, alpha (0-255) one after the other
     */
    public static function of(array $rgba): ?string
    {
        if (count($rgba) !== self::SIZE * self::SIZE * 4) {
            return null;
        }

        $weights = array_fill(0, self::HUE_GROUPS, 0.0);
        $colors = array_fill(0, self::HUE_GROUPS, [0.0, 0.0, 0.0]);

        for ($index = 0; $index < self::SIZE * self::SIZE; $index++) {
            [$red, $green, $blue, $alpha] = array_slice($rgba, $index * 4, 4);

            if ($alpha < 128) {
                continue;
            }

            [$hue, $saturation, $lightness] = SlidePalette::hsl(sprintf('#%02x%02x%02x', $red, $green, $blue));

            if ($saturation < 0.3 || $lightness < 0.15 || $lightness > 0.9) {
                continue;
            }

            $group = (int) floor($hue / 360 * self::HUE_GROUPS) % self::HUE_GROUPS;
            // One pixel, one vote: the hue that covers the most area wins, not the loudest one.
            $weights[$group] += 1.0;
            $colors[$group] = [$colors[$group][0] + $red, $colors[$group][1] + $green, $colors[$group][2] + $blue];
        }

        $best = array_keys($weights, max($weights))[0];

        // Less than 3% of the pixels with a vivid colour: the image is grey, not coloured.
        if ($weights[$best] < self::SIZE * self::SIZE * 0.03) {
            return null;
        }

        return sprintf('#%02x%02x%02x', round($colors[$best][0] / $weights[$best]), round($colors[$best][1] / $weights[$best]), round($colors[$best][2] / $weights[$best]));
    }
}
