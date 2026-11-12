<?php

namespace App\Enums;

/**
 * The periods of the „Descoperă” leaderboard: the growth of a repository is the number of stars it gained
 * between the snapshot of the ranking day and the snapshot taken exactly this many days before.
 * The value is what the page sends in the query string (?period=7d); the page names it in the visitor's
 * language (resources/js/lang, "discover.periods").
 */
enum DiscoverPeriod: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    /**
     * The period of a page opened without ?period: a week is the usual „trending” view and is less noisy
     * than a single day.
     */
    public static function default(): self
    {
        return self::Week;
    }

    /**
     * How many days lie between the two snapshots compared.
     */
    public function days(): int
    {
        return match ($this) {
            self::Day => 1,
            self::Week => 7,
            self::Month => 30,
        };
    }
}
