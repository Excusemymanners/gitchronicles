<?php

namespace App\Enums;

/**
 * The languages the presentation video is narrated in. Each language has its own voice, captions and
 * MP4, and its own row in media_assets (the "language" column); the slides are Romanian only.
 *
 * Which languages are made, and in which order, comes from config('gitchronicle.media.languages')
 * (MEDIA_LANGUAGES, default "en,ro"): the first one is the default shown on the page.
 */
enum MediaLanguage: string
{
    case En = 'en';
    case Ro = 'ro';

    /**
     * The name of the language, in that language (for the switch on the page).
     */
    public function label(): string
    {
        return match ($this) {
            self::En => 'English',
            self::Ro => 'Română',
        };
    }

    /**
     * The configured languages, in order. Unknown codes are ignored; an empty list means Romanian.
     *
     * @return list<self>
     */
    public static function configured(): array
    {
        $languages = [];

        foreach ((array) config('gitchronicle.media.languages') as $code) {
            $language = self::tryFrom(strtolower(trim((string) $code)));

            if ($language !== null && ! in_array($language, $languages, true)) {
                $languages[] = $language;
            }
        }

        return $languages === [] ? [self::Ro] : $languages;
    }

    /**
     * The language shown first on the page and used by the cards of the workspace.
     */
    public static function default(): self
    {
        return self::configured()[0];
    }
}
