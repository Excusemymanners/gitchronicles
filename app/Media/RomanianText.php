<?php

namespace App\Media;

use Illuminate\Support\Str;

/**
 * Small text rules for the video and the slides, in one place:
 *
 * - the Romanian ș and ț are written with a comma below; the old cedilla forms (ş ţ), common in texts
 *   written by AI or copied from old documents, are replaced (the fonts of the video draw only the comma);
 * - a text for the screen or the voice has no control characters, no emoji (the fonts have none)
 *   and no runs of spaces or new lines;
 * - a count of things follows the Romanian grammar: „1 stea”, „2 stele”, „20 de stele”.
 *
 * EnglishText has the same counting and joining rules for the English video.
 */
final class RomanianText
{
    /**
     * The cedilla letters and their correct comma-below forms.
     */
    private const COMMA_BELOW = ['ş' => 'ș', 'Ş' => 'Ș', 'ţ' => 'ț', 'Ţ' => 'Ț'];

    /**
     * ş/ţ → ș/ț.
     */
    public static function commaBelow(string $text): string
    {
        return strtr($text, self::COMMA_BELOW);
    }

    /**
     * A text ready for the screen or the voice: comma-below letters, no emoji or control characters,
     * spaces squeezed. Returns '' when nothing is left.
     */
    public static function clean(string $text): string
    {
        $text = self::commaBelow($text);
        $text = preg_replace('/[\p{Extended_Pictographic}\x{FE0F}\x{200D}]/u', '', $text) ?? '';
        $text = preg_replace('/\p{C}/u', ' ', $text) ?? '';

        return Str::squish($text);
    }

    /**
     * A count with its noun: „1 stea”, „12 stele”, „20 de stele”, „101 stele”, „5.017 linii”.
     * Romanian puts „de” before the noun when the last two digits are 00 or 20–99 (and the number is not 0–19).
     * $formatted adds the thousands separator (for the screen); the voice gets the plain digits.
     */
    public static function count(int $number, string $one, string $many, bool $formatted = true): string
    {
        if ($number === 1) {
            return "1 {$one}";
        }

        $lastTwo = $number % 100;
        $needsDe = $number >= 20 && ($lastTwo === 0 || $lastTwo >= 20);
        $digits = $formatted ? self::number($number) : (string) $number;

        return $digits.($needsDe ? ' de ' : ' ').$many;
    }

    /**
     * A count the way a presenter says it: exact under a thousand, then rounded down and honest about it:
     * „peste 2 mii de stele”, „peste 82 de mii de stele”, „peste un milion de descărcări”.
     */
    public static function spokenCount(int $number, string $one, string $many): string
    {
        if ($number < 1000) {
            return self::count($number, $one, $many, formatted: false);
        }

        $isMillions = $number >= 1_000_000;
        $value = intdiv($number, $isMillions ? 1_000_000 : 1000);
        $isExact = $number === $value * ($isMillions ? 1_000_000 : 1000);

        $amount = match (true) {
            $value === 1 => $isMillions ? 'un milion' : 'o mie',
            default => self::count($value, '', $isMillions ? 'milioane' : 'mii', formatted: false),
        };

        return ($isExact ? '' : 'peste ')."{$amount} de {$many}";
    }

    /**
     * A number written with the Romanian thousands separator: 12345 → „12.345”.
     */
    public static function number(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }

    /**
     * Join phrases the Romanian way: „a”, „a și b”, „a, b și c”.
     *
     * @param  list<string>  $phrases
     */
    public static function join(array $phrases): string
    {
        if (count($phrases) < 2) {
            return $phrases[0] ?? '';
        }

        $last = array_pop($phrases);

        return implode(', ', $phrases).' și '.$last;
    }
}
