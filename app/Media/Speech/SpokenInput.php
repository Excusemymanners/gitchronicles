<?php

namespace App\Media\Speech;

/**
 * What may be handed to a speech engine. A sentence comes from text written about a repository, so it is cut
 * to a length no real sentence has (an engine would otherwise run for minutes on one endless "sentence") and
 * stripped of control characters, which have no sound.
 */
final class SpokenInput
{
    /**
     * The longest sentence given to an engine.
     */
    public const MAX_CHARACTERS = 1500;

    /**
     * The sentence without control characters, cut to MAX_CHARACTERS.
     */
    public static function limit(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? '';

        return mb_substr($clean, 0, self::MAX_CHARACTERS);
    }
}
