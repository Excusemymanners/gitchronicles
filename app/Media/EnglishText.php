<?php

namespace App\Media;

/**
 * The English counterpart of RomanianText, for the English video: counts with their nouns
 * ("1 star", "12 stars"), numbers with the English thousands separator and lists joined with "and".
 */
final class EnglishText
{
    /**
     * A count with its noun: "1 star", "12 stars", "5,017 lines".
     * $formatted adds the thousands separator (for the screen); the voice gets the plain digits.
     */
    public static function count(int $number, string $one, string $many, bool $formatted = true): string
    {
        return ($formatted ? self::number($number) : (string) $number).' '.($number === 1 ? $one : $many);
    }

    /**
     * A count the way a presenter says it: exact under a thousand, then rounded down and honest about it:
     * "over 82 thousand stars", "over 3 million downloads".
     */
    public static function spokenCount(int $number, string $one, string $many): string
    {
        if ($number < 1000) {
            return self::count($number, $one, $many, formatted: false);
        }

        [$value, $unit] = $number >= 1_000_000
            ? [intdiv($number, 1_000_000), 'million']
            : [intdiv($number, 1000), 'thousand'];
        $isExact = $number === $value * ($unit === 'million' ? 1_000_000 : 1000);

        return ($isExact ? '' : 'over ')."{$value} {$unit} {$many}";
    }

    /**
     * A number written with the English thousands separator: 12345 → "12,345".
     */
    public static function number(int $number): string
    {
        return number_format($number, 0, '.', ',');
    }

    /**
     * Join phrases the English way: "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $phrases
     */
    public static function join(array $phrases): string
    {
        if (count($phrases) < 2) {
            return $phrases[0] ?? '';
        }

        $last = array_pop($phrases);

        return implode(', ', $phrases).' and '.$last;
    }
}
