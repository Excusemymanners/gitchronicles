<?php

namespace App\Media;

use Illuminate\Support\Str;

/**
 * The scenes built without AI, only from the facts: the opening with the name and the description,
 * „Cum începi” with the README's command, the numbers from GitHub and the analysis, and the closing
 * with the address. The AI script uses the same numbers scene, and these scenes replace any AI scene
 * that does not pass the checks.
 *
 * A text quoted from the repository (the description) may be in English; the voice is Romanian, so it
 * reads such a text only when it has Romanian letters, otherwise it says only the project's name.
 */
final class TemplateScenes
{
    /**
     * The longest description shown on the opening scene; a longer one is cut at a word.
     */
    public const HOOK_CHARACTERS = 120;

    /**
     * The opening: the owner, the name and (when known) the first sentence of the description.
     *
     * @return array<string, mixed>
     */
    public static function hook(PresentationFacts $facts): array
    {
        $scene = ['key' => 'hook', 'label' => 'Ce este', 'kicker' => "{$facts->owner} /", 'title' => $facts->name];
        $description = $facts->description;

        if ($description === null) {
            return [...$scene, 'text' => null, 'accent' => null, 'narration' => "Acesta este {$facts->name}.", 'source' => null];
        }

        $text = self::shorten($description);
        $source = $facts->findSource(rtrim($text, '….')) ?? [
            'label' => isset($facts->github['description']) ? 'GitHub' : 'README.md',
            'quote' => $text,
            'url' => $facts->url,
        ];

        return [
            ...$scene,
            'text' => $text,
            'accent' => null,
            'narration' => self::isRomanian($text) ? rtrim($text, '…') : "Acesta este {$facts->name}.",
            'source' => $source,
        ];
    }

    /**
     * „Cum începi”: the first command of a README code block, or null when the README shows none.
     *
     * @return array<string, mixed>|null
     */
    public static function start(PresentationFacts $facts): ?array
    {
        if ($facts->startCommand === null) {
            return null;
        }

        return [
            'key' => 'start',
            'label' => 'Cum începi',
            'text' => 'Pornești cu o comandă din README.',
            'accent' => 'o comandă',
            'command' => $facts->startCommand['command'],
            'narration' => 'Pornești cu o comandă din README.',
            'source' => ['label' => 'README.md', 'quote' => $facts->startCommand['command'], 'url' => $facts->startCommand['url']],
        ];
    }

    /**
     * The numbers: the stars on GitHub, the language(s) and the license as chips, and one small line with
     * the size of the code found by the analysis. Null when none of them is known.
     *
     * @return array<string, mixed>|null
     */
    public static function proof(PresentationFacts $facts): ?array
    {
        $stars = $facts->github !== null && $facts->github['stars'] > 0 ? $facts->github['stars'] : null;
        $languages = array_values(array_unique(array_filter([
            $facts->github['language'] ?? $facts->languages[0]['name'] ?? null,
            ...array_map(fn (array $language): ?string => $language['percent'] >= 10 ? $language['name'] : null, $facts->languages),
        ])));
        $languages = array_slice($languages, 0, 2);

        if ($stars === null && $languages === [] && $facts->license === null) {
            return null;
        }

        $clauses = array_values(array_filter([
            $stars === null ? null : 'are '.RomanianText::count($stars, 'stea', 'stele', formatted: false).' pe GitHub',
            $languages === [] ? null : 'e scris în '.RomanianText::join($languages),
            $facts->license === null ? null : "are licența {$facts->license}",
        ]));

        $quoteParts = array_values(array_filter([
            $stars === null ? null : "Stele: {$stars}",
            $languages === [] ? null : 'Limbaj: '.implode(', ', $languages),
            $facts->license === null ? null : "Licență: {$facts->license}",
        ]));

        return [
            'key' => 'proof',
            'label' => $stars === null ? 'Pe scurt' : 'Pe GitHub',
            'stars' => $stars,
            'chips' => array_values(array_filter([...$languages, $facts->license])),
            'note' => $facts->stats['files'] > 0
                ? RomanianText::count($facts->stats['files'], 'fișier', 'fișiere').' și '.RomanianText::count($facts->stats['lines'], 'linie', 'linii').' de cod, analizate de GitChronicle'
                : null,
            'narration' => Str::ucfirst(RomanianText::join($clauses)).'.',
            'source' => [
                'label' => $facts->github === null ? 'Analiza codului' : 'GitHub și analiza codului',
                'quote' => implode(' · ', $quoteParts),
                'url' => $facts->github === null ? null : $facts->url,
            ],
        ];
    }

    /**
     * The closing: an invitation and the repository's address on GitHub.
     *
     * @param  array{text: string, accent: string|null, narration: string, source: array{label: string, quote: string, url: string|null}}|null  $written  the AI's text, when it passed the checks
     * @return array<string, mixed>
     */
    public static function cta(PresentationFacts $facts, ?array $written = null): array
    {
        return [
            'key' => 'cta',
            'label' => 'Unde îl găsești',
            'text' => $written['text'] ?? 'Vezi proiectul pe GitHub.',
            'accent' => $written === null ? 'pe GitHub' : $written['accent'],
            'url' => $facts->shortUrl(),
            'narration' => $written['narration'] ?? 'Îl găsești pe GitHub, la adresa de pe ecran.',
            'source' => $written['source'] ?? ['label' => 'GitHub', 'quote' => $facts->shortUrl(), 'url' => $facts->url],
        ];
    }

    /**
     * The first sentence of a description, short enough for the opening scene: cut at the last colon,
     * semicolon or dash that fits (the clause reads well alone), else at a word, with „…”.
     */
    public static function shorten(string $description): string
    {
        $sentence = preg_split('/(?<=[.!?])\s+/u', $description)[0] ?? $description;

        if (mb_strlen($sentence) <= self::HOOK_CHARACTERS) {
            return $sentence;
        }

        $clause = mb_substr($sentence, 0, self::HOOK_CHARACTERS + 1);

        if (preg_match('/\A(.{30,})[:;—–]/us', $clause, $match) === 1) {
            return rtrim($match[1]).'.';
        }

        return Str::limit($sentence, self::HOOK_CHARACTERS, '…', preserveWords: true);
    }

    /**
     * Whether a text is Romanian enough for the Romanian voice: it has a letter only Romanian uses here.
     */
    public static function isRomanian(string $text): bool
    {
        return preg_match('/[ăâîșțĂÂÎȘȚ]/u', $text) === 1;
    }
}
