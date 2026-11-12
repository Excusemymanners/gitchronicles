<?php

namespace App\Media;

use App\Enums\MediaLanguage;
use Illuminate\Support\Str;

/**
 * The scenes built without AI, only from the facts, in English and in Romanian at once:
 *
 * - the opening with the name and the description;
 * - a walk through the README's sections (used only when there is no AI script);
 * - „Cum e construit”: the languages and the modules found by the code analysis;
 * - „În cifre”: the stars and forks from GitHub, the commits and contributors of the imported history;
 * - „Cum începi” with the README's command;
 * - the closing, which points to the full documentation on GitChronicle.
 *
 * The AI script uses the same „Cum e construit”, „În cifre” and closing scenes (their numbers and names
 * never pass through the AI), and these scenes replace any AI scene that does not pass the checks.
 *
 * A scene is an array with what both languages share ("key", "source", "heading", "command", ...) and,
 * under "en" and "ro", what is said and shown in each ("label", "text", "narration", "chips", "stats").
 * A text quoted from the repository (the description) is read aloud only by the voice of its own
 * language; the other voice says only the project's name.
 */
final class TemplateScenes
{
    /**
     * The longest description shown on the opening scene; a longer one is cut at a word.
     */
    public const HOOK_CHARACTERS = 120;

    /**
     * How many README sections the walk-through names.
     */
    public const TOUR_SECTIONS = 3;

    /**
     * README sections that say nothing about what the project does.
     */
    private const SKIPPED_HEADINGS = [
        'license', 'licență', 'licenta', 'contributing', 'contribuții', 'changelog', 'credits', 'authors', 'autori',
        'acknowledgements', 'acknowledgments', 'table of contents', 'cuprins', 'support', 'sponsors', 'security',
        'code of conduct', 'community', 'faq',
    ];

    /**
     * File formats the analysis counts as languages but nobody "writes a project in".
     */
    private const NOT_PROGRAMMING = ['Markdown', 'MDX', 'reStructuredText', 'AsciiDoc', 'JSON', 'XML', 'YAML', 'TOML', 'Text', 'INI', 'CSV', 'SVG'];

    /**
     * The name of each kind of scene, per language.
     *
     * @var array<string, array{en: string, ro: string}>
     */
    public const LABELS = [
        'hook' => ['en' => 'What it is', 'ro' => 'Ce este'],
        'problem' => ['en' => 'The problem', 'ro' => 'Ce rezolvă'],
        'feature' => ['en' => 'What it does', 'ro' => 'Ce face'],
        'tour' => ['en' => 'In the README', 'ro' => 'În README'],
        'built' => ['en' => 'How it is built', 'ro' => 'Cum e construit'],
        'proof' => ['en' => 'In numbers', 'ro' => 'În cifre'],
        'start' => ['en' => 'Get started', 'ro' => 'Cum începi'],
        'cta' => ['en' => 'Read more', 'ro' => 'Mai departe'],
    ];

    /**
     * The opening: the owner, the name and (when known) the first sentence of the description.
     *
     * @param  array{en: string, ro: string, source: array{label: string, quote: string, url: string|null}}|null  $written  the AI's lines, when they passed the checks
     * @return array<string, mixed>
     */
    public static function hook(PresentationFacts $facts, ?array $written = null): array
    {
        $scene = ['key' => 'hook', 'kicker' => "{$facts->owner} /", 'title' => $facts->name, 'heading' => null];

        if ($written !== null) {
            return [...$scene, 'quote' => $written['source']['quote'], 'source' => $written['source'], ...self::spoken('hook', $written['en'], $written['ro'])];
        }

        $plain = [
            'en' => "This is {$facts->name}, from {$facts->owner} on GitHub.",
            'ro' => "Acesta este {$facts->name}, de la {$facts->owner}, pe GitHub.",
        ];

        if ($facts->description === null) {
            return [...$scene, 'quote' => null, 'source' => null, ...self::spoken('hook', $plain['en'], $plain['ro'], text: null)];
        }

        $text = self::shorten($facts->description);
        $quote = rtrim($text, '….');
        $source = $facts->findSource($quote) ?? [
            'label' => isset($facts->github['description']) ? 'GitHub' : 'README.md',
            'quote' => $text,
            'url' => $facts->url,
        ];
        $writtenIn = SpokenText::languageOf($text);
        $lines = [];

        foreach (MediaLanguage::cases() as $language) {
            // The description is read only by the voice of the language it is written in.
            $lines[$language->value] = $writtenIn === $language
                ? Str::ucfirst($facts->name).'. '.self::sentence(rtrim($text, '…'))
                : $plain[$language->value];
        }

        return [...$scene, 'quote' => $quote, 'source' => $source, ...self::spoken('hook', $lines['en'], $lines['ro'], text: $text)];
    }

    /**
     * The walk through the README, for a script without AI: the first sections that are about the project,
     * named by their real headings (the camera stops on each one). Null with fewer than two such sections.
     *
     * @return array<string, mixed>|null
     */
    public static function tour(PresentationFacts $facts): ?array
    {
        $sections = array_values(array_filter(
            $facts->headings,
            fn (string $heading): bool => ! in_array(mb_strtolower($heading), self::SKIPPED_HEADINGS, true)
                && ! self::isProjectName($heading, $facts->name)
                && mb_strlen($heading) <= 40,
        ));
        $sections = array_slice($sections, 0, self::TOUR_SECTIONS);

        if (count($sections) < 2) {
            return null;
        }

        $english = 'Its README walks you through '.EnglishText::join($sections).'.';
        $romanian = 'README-ul lui te duce prin '.RomanianText::join($sections).'.';

        return [
            'key' => 'feature',
            'index' => 1,
            'count' => 1,
            'heading' => $sections[0],
            'headings' => $sections,
            'quote' => null,
            'source' => ['label' => 'README.md', 'quote' => implode(' · ', $sections), 'url' => $facts->sources['README.md']['url'] ?? null],
            'en' => ['label' => self::LABELS['tour']['en'], 'title' => 'Inside the README', 'text' => $english, 'narration' => $english],
            'ro' => ['label' => self::LABELS['tour']['ro'], 'title' => 'Ce găsești în README', 'text' => $romanian, 'narration' => $romanian],
        ];
    }

    /**
     * „Cum e construit”: the main languages (GitHub's first, then the analysis' ones with at least a tenth
     * of the lines) and how many modules the analysis found; the biggest modules are shown as chips.
     * Null when no language is known.
     *
     * @return array<string, mixed>|null
     */
    public static function built(PresentationFacts $facts): ?array
    {
        $languages = self::mainLanguages($facts);

        if ($languages === []) {
            return null;
        }

        $modules = $facts->stats['modules'];
        $chips = array_values(array_map(
            fn (array $language): string => "{$language['name']} {$language['percent']}%",
            array_filter($facts->languages, fn (array $language): bool => in_array($language['name'], $languages, true)),
        ));
        $chips = $chips === [] ? $languages : $chips;

        $lines = [
            'en' => 'Under the hood it is written mostly in '.EnglishText::join($languages)
                .($modules > 1 ? ', organised in '.SpokenText::count($modules, 'module', MediaLanguage::En).'.' : '.'),
            'ro' => 'Sub capotă e scris mai ales în '.RomanianText::join($languages)
                .($modules > 1 ? ', organizat în '.SpokenText::count($modules, 'module', MediaLanguage::Ro).'.' : '.'),
        ];

        $scene = [
            'key' => 'built',
            'heading' => null,
            'quote' => null,
            'modules' => array_slice(array_column($facts->modules, 'path'), 0, 3),
        ];

        foreach (MediaLanguage::cases() as $language) {
            $scene[$language->value] = [
                'label' => self::LABELS['built'][$language->value],
                'text' => $lines[$language->value],
                'narration' => $lines[$language->value],
                'chips' => $chips,
                'note' => $language === MediaLanguage::Ro ? 'din analiza codului făcută de GitChronicle' : 'from GitChronicle\'s code analysis',
                'source' => [
                    'label' => $language === MediaLanguage::Ro ? 'Analiza codului' : 'Code analysis',
                    'quote' => implode(' · ', array_filter([...$chips, $modules > 1 ? SpokenText::shownCount($modules, 'module', $language) : null])),
                    'url' => null,
                ],
            ];
        }

        return $scene;
    }

    /**
     * „În cifre”: one sentence with the stars and forks of GitHub (when it has stars), then the commits,
     * the contributors and the month of the last commit from the imported history. The numbers of the
     * history are also shown as cards, because the GitHub page does not display them.
     * Null when neither is known.
     *
     * @return array<string, mixed>|null
     */
    public static function proof(PresentationFacts $facts): ?array
    {
        $stars = $facts->github !== null && $facts->github['stars'] > 0 ? $facts->github['stars'] : null;
        $forks = $stars !== null && $facts->github['forks'] > 0 ? $facts->github['forks'] : null;
        $history = $facts->history;

        if ($stars === null && $history === null) {
            return null;
        }

        $languages = self::mainLanguages($facts);
        $scene = [
            'key' => 'proof',
            'heading' => null,
            'quote' => null,
            'stars' => $stars,
            'has_forks' => $forks !== null,
            'has_history' => $history !== null,
        ];

        foreach (MediaLanguage::cases() as $language) {
            $isRomanian = $language === MediaLanguage::Ro;
            $sentences = [];
            $stats = [];

            if ($stars !== null) {
                $counted = array_values(array_filter([
                    SpokenText::count($stars, 'star', $language),
                    $forks === null ? null : SpokenText::count($forks, 'fork', $language),
                ]));
                $sentences[] = ($isRomanian ? 'Pe GitHub are ' : 'On GitHub it has ').SpokenText::join($counted, $language).'.';
            }

            if ($history !== null) {
                $commits = SpokenText::count($history['commit_count'], 'commit', $language);
                $contributors = SpokenText::count($history['contributor_count'], 'contributor', $language);
                $month = SpokenText::monthYear($history['last_commit_at'], $language);
                $sentences[] = $isRomanian
                    ? Str::ucfirst($contributors).($history['contributor_count'] === 1 ? ' a făcut ' : ' au făcut ')."{$commits}. Cel mai recent e din {$month}."
                    : Str::ucfirst($contributors)." made {$commits}. The latest one is from {$month}.";

                $stats = [
                    ['value' => self::number($history['commit_count'], $language), 'label' => SpokenText::noun($history['commit_count'], 'commit', $language)],
                    ['value' => self::number($history['contributor_count'], $language), 'label' => SpokenText::noun($history['contributor_count'], 'contributor', $language)],
                    ['value' => Str::ucfirst($month), 'label' => $isRomanian ? 'ultimul commit' : 'latest commit'],
                ];
            }

            $scene[$language->value] = [
                'label' => self::LABELS['proof'][$language->value],
                'text' => implode(' ', $sentences),
                'narration' => implode(' ', $sentences),
                'stats' => $stats,
                'chips' => array_values(array_filter([...$languages, $facts->license])),
                'note' => $isRomanian ? 'din istoricul git importat de GitChronicle' : 'from the git history imported by GitChronicle',
                'source' => [
                    'label' => match (true) {
                        $stars !== null && $history !== null => $isRomanian ? 'GitHub și istoricul git' : 'GitHub and the git history',
                        $stars !== null => 'GitHub',
                        default => $isRomanian ? 'Istoricul git' : 'The git history',
                    },
                    'quote' => implode(' · ', array_filter([
                        $stars === null ? null : SpokenText::shownCount($stars, 'star', $language),
                        $forks === null ? null : SpokenText::shownCount($forks, 'fork', $language),
                        $history === null ? null : SpokenText::shownCount($history['commit_count'], 'commit', $language),
                        $history === null ? null : SpokenText::shownCount($history['contributor_count'], 'contributor', $language),
                        $history === null ? null : ($isRomanian ? 'ultimul commit: ' : 'latest commit: ').SpokenText::monthYear($history['last_commit_at'], $language),
                    ])),
                    'url' => $stars === null ? null : $facts->url,
                ],
            ];
        }

        return $scene;
    }

    /**
     * „Cum începi”: the first command of a README code block, or null when the README shows none.
     *
     * @param  array{en: string, ro: string, command: string}|null  $written  the AI's lines and the command it chose, when they passed the checks
     * @return array<string, mixed>|null
     */
    public static function start(PresentationFacts $facts, ?array $written = null): ?array
    {
        $command = $written['command'] ?? $facts->startCommand['command'] ?? null;

        if ($command === null) {
            return null;
        }

        return [
            'key' => 'start',
            'heading' => null,
            'quote' => $command,
            'command' => $command,
            'source' => ['label' => 'README.md', 'quote' => $command, 'url' => $facts->startCommand['url'] ?? $facts->sources['README.md']['url'] ?? null],
            ...self::spoken(
                'start',
                $written['en'] ?? 'To try it yourself, the README starts with this command.',
                $written['ro'] ?? 'Ca să îl încerci, README-ul începe cu această comandă.',
            ),
        ];
    }

    /**
     * The closing: where the rest is — the full documentation, the diagrams and the history on GitChronicle —
     * with the repository's address on GitHub on the screen.
     *
     * @return array<string, mixed>
     */
    public static function cta(PresentationFacts $facts): array
    {
        return [
            'key' => 'cta',
            'heading' => null,
            'quote' => null,
            'url' => $facts->shortUrl(),
            'source' => ['label' => 'GitChronicle', 'quote' => $facts->shortUrl(), 'url' => $facts->url],
            ...self::spoken(
                'cta',
                'The full documentation, the architecture diagrams and the history are on GitChronicle.',
                'Documentația completă, diagramele de arhitectură și istoricul sunt pe GitChronicle.',
            ),
        ];
    }

    /**
     * Whether a README heading is just the project's name (its title), written with other letter case,
     * spaces or a plural: "GitChronicle" for the repository "gitchronicles".
     */
    private static function isProjectName(string $heading, string $name): bool
    {
        $heading = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($heading)) ?? '';
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($name)) ?? '';

        return $heading !== '' && (str_starts_with($name, $heading) || str_starts_with($heading, $name));
    }

    /**
     * The "en" and "ro" parts of a scene. The screen text is what the voice says, unless $text gives
     * another one (null = no text on the screen).
     *
     * @return array{en: array<string, mixed>, ro: array<string, mixed>}
     */
    public static function spoken(string $key, string $english, string $romanian, string|null|false $text = false): array
    {
        return [
            'en' => ['label' => self::LABELS[$key]['en'], 'text' => $text === false ? $english : $text, 'narration' => $english],
            'ro' => ['label' => self::LABELS[$key]['ro'], 'text' => $text === false ? $romanian : $text, 'narration' => $romanian],
        ];
    }

    /**
     * The languages the project is written in: GitHub's main language first, then the analysis' languages
     * with at least a tenth of the lines, without documentation and data formats. At most three.
     *
     * @return list<string>
     */
    public static function mainLanguages(PresentationFacts $facts): array
    {
        $languages = array_filter([
            $facts->github['language'] ?? null,
            ...array_map(fn (array $language): ?string => $language['percent'] >= 10 ? $language['name'] : null, $facts->languages),
        ], fn (?string $language): bool => $language !== null && ! in_array($language, self::NOT_PROGRAMMING, true));

        return array_slice(array_values(array_unique($languages)), 0, 3);
    }

    /**
     * A description short enough for the opening scene: whole when it fits, else its first sentence, cut
     * at the last colon, semicolon or dash that fits (the clause reads well alone), else at a word, with „…”.
     */
    public static function shorten(string $description): string
    {
        if (mb_strlen($description) <= self::HOOK_CHARACTERS) {
            return $description;
        }

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
     * A text read as its own sentence: it gets a full stop when it has no ending punctuation.
     */
    public static function sentence(string $text): string
    {
        return Str::endsWith($text, ['.', '!', '?', ':']) ? $text : "{$text}.";
    }

    /**
     * A number for the screen, with the thousands separator of the language.
     */
    private static function number(int $number, MediaLanguage $language): string
    {
        return $language === MediaLanguage::Ro ? RomanianText::number($number) : EnglishText::number($number);
    }
}
