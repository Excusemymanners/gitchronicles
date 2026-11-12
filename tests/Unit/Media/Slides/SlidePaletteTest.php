<?php

namespace Tests\Unit\Media\Slides;

use App\Media\Slides\DominantColor;
use App\Media\Slides\SlidePalette;
use PHPUnit\Framework\TestCase;

class SlidePaletteTest extends TestCase
{
    public function test_contrast_matches_the_wcag_definition(): void
    {
        $this->assertEqualsWithDelta(21.0, SlidePalette::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, SlidePalette::contrast('#777777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(4.54, SlidePalette::contrast('#767676', '#ffffff'), 0.02);
    }

    public function test_every_text_colour_has_enough_contrast_for_any_accent(): void
    {
        $accents = ['#000000', '#ffffff', '#808080', '#f1e05a', '#ffff00', '#00ffff', '#ff0000', '#0000ff', '#dea584', '#00add8'];

        for ($hue = 0; $hue < 360; $hue += 15) {
            foreach ([0.1, 0.5, 1.0] as $saturation) {
                foreach ([0.2, 0.5, 0.8] as $lightness) {
                    $accents[] = SlidePalette::fromHsl((float) $hue, $saturation, $lightness);
                }
            }
        }

        foreach ($accents as $accent) {
            $tokens = SlidePalette::fromAccent($accent);

            foreach (SlidePalette::textPairs() as [$text, $background, $description]) {
                $this->assertGreaterThanOrEqual(
                    SlidePalette::MINIMUM_CONTRAST,
                    SlidePalette::contrast($tokens[$text], $tokens[$background]),
                    "{$description} ({$text} on {$background}) for the accent {$accent}",
                );
            }
        }
    }

    public function test_the_palette_stays_calm_whatever_the_accent(): void
    {
        for ($hue = 0; $hue < 360; $hue += 10) {
            foreach ([0.2, 0.6, 1.0] as $saturation) {
                $tokens = SlidePalette::fromAccent(SlidePalette::fromHsl((float) $hue, $saturation, 0.5));
                [, $brandSaturation, $brandLightness] = SlidePalette::hsl($tokens['brand']);
                [, $accentSaturation, $accentLightness] = SlidePalette::hsl($tokens['accent']);

                $this->assertLessThanOrEqual(0.3, $brandLightness, "brand lightness for hue {$hue}");
                $this->assertLessThanOrEqual(SlidePalette::BRAND_SATURATION_MAX + 0.03, $brandSaturation, "brand saturation for hue {$hue}");
                $this->assertLessThanOrEqual(SlidePalette::ACCENT_SATURATION_MAX + 0.03, $accentSaturation, "accent saturation for hue {$hue}");
                $this->assertGreaterThan(0.25, $accentLightness);
                $this->assertLessThan(0.65, $accentLightness);
            }
        }
    }

    public function test_the_hue_with_the_most_area_wins(): void
    {
        $pixels = [];

        for ($index = 0; $index < 48 * 48; $index++) {
            $pixels = [...$pixels, ...($index % 5 === 0 ? [255, 0, 0, 255] : ($index % 5 < 4 ? [0, 0, 200, 255] : [255, 255, 255, 255]))];
        }

        $this->assertSame('#0000c8', DominantColor::of($pixels));
    }

    public function test_colours_are_normalised_and_converted(): void
    {
        $this->assertSame('#aabbcc', SlidePalette::normalize('ABC'));
        $this->assertSame('#000000', SlidePalette::normalize('not a colour'));
        $this->assertSame('#ff0000', SlidePalette::fromHsl(0, 1, 0.5));
        $this->assertEqualsWithDelta(120.0, SlidePalette::hsl('#00ff00')[0], 0.01);
    }

    public function test_the_dominant_colour_ignores_transparent_white_and_grey_pixels(): void
    {
        $pixels = [];

        for ($index = 0; $index < 48 * 48; $index++) {
            $pixels = [...$pixels, ...($index % 4 === 0 ? [200, 30, 30, 255] : ($index % 4 === 1 ? [255, 255, 255, 255] : ($index % 4 === 2 ? [120, 120, 120, 255] : [0, 0, 255, 0])))];
        }

        $this->assertSame('#c81e1e', DominantColor::of($pixels));
    }

    public function test_a_grey_image_has_no_dominant_colour(): void
    {
        $this->assertNull(DominantColor::of(array_merge(...array_fill(0, 48 * 48, [[90, 90, 90, 255]]))));
        $this->assertNull(DominantColor::of([1, 2, 3]));
    }
}
