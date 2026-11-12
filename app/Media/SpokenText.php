<?php

namespace App\Media;

use App\Enums\MediaLanguage;
use Carbon\CarbonImmutable;

/**
 * What the voice of each language needs, in one place:
 *
 * - counts, lists and dates worded in the language of the video (RomanianText, EnglishText);
 * - the text handed to Piper: the captions keep the real spelling ("TypeScript", "README"), but the
 *   voice gets a respelled form where its reading rules would say the word wrong. The Romanian voice
 *   reads letter by letter the Romanian way, so English technology names are respelled for it
 *   ("TypeScript" → „taipscript”); the English voice reads technology names as they are and only
 *   needs the abbreviations spelled out ("npm" → "N P M");
 * - a guess of the language a text is written in, so a voice never reads a description written in
 *   the other language.
 */
final class SpokenText
{
    /**
     * The nouns counted in the video: singular and plural, per language.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const NOUNS = [
        'star' => ['en' => ['star', 'stars'], 'ro' => ['stea', 'stele']],
        'fork' => ['en' => ['fork', 'forks'], 'ro' => ['fork', 'forkuri']],
        'commit' => ['en' => ['commit', 'commits'], 'ro' => ['commit', 'commituri']],
        'contributor' => ['en' => ['contributor', 'contributors'], 'ro' => ['contribuitor', 'contribuitori']],
        'module' => ['en' => ['module', 'modules'], 'ro' => ['modul', 'module']],
        'file' => ['en' => ['file', 'files'], 'ro' => ['fișier', 'fișiere']],
    ];

    /**
     * How the Romanian voice should say common technology words: whole words, in any letter case — except
     * the keys written with a capital letter ("AI", "Go"), which are also ordinary Romanian or English
     * words in lower case and are replaced only when written exactly so.
     *
     * @var array<string, string>
     */
    private const ROMANIAN_PRONUNCIATION = [
        'github' => 'ghithab', 'git' => 'ghit', 'readme' => 'ridmi', 'typescript' => 'taipscript',
        'javascript' => 'geavascript', 'python' => 'paiton', 'react' => 'riact', 'laravel' => 'laravel',
        'node' => 'noud', 'node.js' => 'noud gei es', 'vue' => 'viu', 'rust' => 'rast', 'ruby' => 'rubi',
        'java' => 'geava', 'shell' => 'șel', 'docker' => 'docăr', 'html' => 'haș te me le', 'css' => 'ce se se',
        'api' => 'ei pi ai', 'cli' => 'si el ai', 'AI' => 'ei ai', 'sql' => 'es chiu el', 'php' => 'pe haș pe',
        'json' => 'geison', 'commit' => 'comit', 'commituri' => 'comituri', 'fork' => 'forc', 'forkuri' => 'forcuri',
        'open-source' => 'open sors', 'open source' => 'open sors', 'framework' => 'freimuărc', 'gitchronicle' => 'ghit cronicăl',
        'tailwind' => 'teiluind', 'vite' => 'vit', 'npm' => 'en pe em', 'npx' => 'en pe ics', 'c++' => 'ce plus plus',
        'c#' => 'ce șarp', 'Go' => 'gou', 'swift' => 'suift', 'kotlin' => 'cotlin',
        'mp4' => 'em pe patru', 'pdf' => 'pe de fe', 'web' => 'ueb', 'software' => 'softuer', 'pull request' => 'pul ricuest',
        'c4' => 'ce patru', 'deepwiki' => 'dip uichi', 'time travel' => 'taim travăl', 'slider' => 'slaidăr',
    ];

    /**
     * Abbreviations the English voice should spell out.
     *
     * @var array<string, string>
     */
    private const ENGLISH_PRONUNCIATION = [
        'readme' => 'read me', 'npm' => 'N P M', 'npx' => 'N P X', 'cli' => 'C L I', 'api' => 'A P I',
        'sql' => 'sequel', 'css' => 'C S S', 'html' => 'H T M L', 'php' => 'P H P', 'json' => 'jason',
        'mp4' => 'M P 4', 'pdf' => 'P D F', 'c4' => 'C 4', 'gitchronicle' => 'git chronicle',
    ];

    /**
     * A count of things, worded for the voice: "over 82 thousand stars", „peste 82 de mii de stele”.
     * $thing is a key of NOUNS.
     */
    public static function count(int $number, string $thing, MediaLanguage $language): string
    {
        [$one, $many] = self::NOUNS[$thing][$language->value];

        return $language === MediaLanguage::Ro
            ? RomanianText::spokenCount($number, $one, $many)
            : EnglishText::spokenCount($number, $one, $many);
    }

    /**
     * A count of things for the screen: "81,962 stars", „81.962 de stele”.
     */
    public static function shownCount(int $number, string $thing, MediaLanguage $language): string
    {
        [$one, $many] = self::NOUNS[$thing][$language->value];

        return $language === MediaLanguage::Ro ? RomanianText::count($number, $one, $many) : EnglishText::count($number, $one, $many);
    }

    /**
     * Only the noun of a count ("stars", „stele”), for a number shown big with its label under it.
     */
    public static function noun(int $number, string $thing, MediaLanguage $language): string
    {
        return self::NOUNS[$thing][$language->value][$number === 1 ? 0 : 1];
    }

    /**
     * "a, b and c" / „a, b și c”.
     *
     * @param  list<string>  $phrases
     */
    public static function join(array $phrases, MediaLanguage $language): string
    {
        return $language === MediaLanguage::Ro ? RomanianText::join($phrases) : EnglishText::join($phrases);
    }

    /**
     * The month and the year of a date, in words: "November 2026", „noiembrie 2026”.
     */
    public static function monthYear(string $date, MediaLanguage $language): string
    {
        return CarbonImmutable::parse($date)->locale($language->value)->translatedFormat('F Y');
    }

    /**
     * The text handed to the voice: the caption text with the words its reading rules get wrong respelled.
     */
    public static function for(string $text, MediaLanguage $language): string
    {
        $words = $language === MediaLanguage::Ro ? self::ROMANIAN_PRONUNCIATION : self::ENGLISH_PRONUNCIATION;
        $text = str_replace(['`', '*', '„', '”', '“', '"'], '', $text);
        // A slash or an underscore inside a name (app/Media, snake_case) is read as a pause, not as a symbol.
        $text = preg_replace('#(?<=\w)[/_](?=\w)#u', ' ', $text) ?? $text;

        // Longer names first, so "node.js" is not read as "node" followed by ".js".
        uksort($words, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        foreach ($words as $word => $spoken) {
            $flags = $word === mb_strtolower($word) ? 'iu' : 'u';
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/'.$flags, $spoken, $text) ?? $text;
        }

        return $text;
    }

    /**
     * The language a text is most likely written in, or null when it cannot be told: Romanian by its
     * own letters or its most common short words, English by its most common short words.
     */
    public static function languageOf(string $text): ?MediaLanguage
    {
        $lower = ' '.mb_strtolower($text).' ';

        if (preg_match('/[ăâîșț]/u', $lower) === 1 || preg_match_all('/ (și|este|sunt|pentru|care|din|sau|cu|un|o) /u', $lower) >= 3) {
            return MediaLanguage::Ro;
        }

        if (preg_match('/^[\x20-\x7E\s–—’‘“”…]+$/u', $text) === 1 && preg_match_all('/ (the|and|for|with|is|are|of|to|a|an|in|your|you|that|from|by) /', $lower) >= 1) {
            return MediaLanguage::En;
        }

        return null;
    }
}
