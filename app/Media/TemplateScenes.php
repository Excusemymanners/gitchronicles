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
 * - the closing, with the repository's address and GitChronicle as a small credit on the screen.
 *
 * The sentences are written to be said aloud: short, with contractions in English, in natural spoken
 * Romanian (not translated), each scene with its own sentence shape.
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
            'en' => "This is {$facts->name}, by {$facts->owner}.",
            'ro' => "Acesta e {$facts->name}, de la {$facts->owner}.",
        ];

        if ($facts->description === null) {
            return [...$scene, 'quote' => null, 'source' => null, ...self::spoken(
                'hook',
                $plain['en']." Let's see what's inside.",
                $plain['ro'].' Hai să vedem ce are înăuntru.',
                text: null,
            )];
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
                ? $plain[$language->value].' '.self::sentence(rtrim($text, '…'))
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

        $english = "Open the README and you'll find ".EnglishText::join($sections).'.';
        $romanian = 'Deschizi README-ul și dai de '.RomanianText::join($sections).'.';

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
            'en' => "Under the hood, it's mostly ".EnglishText::join($languages)
                .($modules > 1 ? ', split into '.SpokenText::count($modules, 'module', MediaLanguage::En).'.' : '.'),
            'ro' => 'Pe dinăuntru e scris mai ales în '.RomanianText::join($languages)
                .($modules > 1 ? ', împărțit în '.SpokenText::count($modules, 'module', MediaLanguage::Ro).'.' : '.'),
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
                'note' => $language === MediaLanguage::Ro ? 'din analiza codului' : 'from the code analysis',
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
                $sentences[] = $isRomanian
                    ? 'Pe GitHub a strâns '.SpokenText::join($counted, $language).'.'
                    : "It's picked up ".SpokenText::join($counted, $language).' on GitHub.';
            }

            if ($history !== null) {
                $commits = SpokenText::count($history['commit_count'], 'commit', $language);
                $contributors = SpokenText::count($history['contributor_count'], 'contributor', $language);
                $month = SpokenText::monthYear($history['last_commit_at'], $language);
                // Two sentences, in the order the camera shows them: the commits with the latest one (top of the
                // page), then the people (the contributors, near the end of the page).
                $sentences[] = $isRomanian
                    ? "Până acum, {$commits} — ultimul în {$month}."
                    : Str::ucfirst($commits)." so far — the latest in {$month}.";
                $sentences[] = match (true) {
                    $history['contributor_count'] === 1 => $isRomanian ? 'Totul e munca unui singur om.' : "So far, it's the work of one person.",
                    $isRomanian => "La el au lucrat {$contributors}.",
                    default => Str::ucfirst($contributors).' have worked on it.',
                };

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
                'note' => $isRomanian ? 'din istoricul git' : 'from the git history',
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
                $written['en'] ?? 'Want to try it? The first step in the README is the command on screen.',
                $written['ro'] ?? 'Vrei să-l încerci? Primul pas din README e comanda de pe ecran.',
            ),
        ];
    }

    /**
     * The closing: where to find the repository, with its address on GitHub on the screen. The last spoken
     * line is the AI's ending when it passed the checks, otherwise where the repository is. The video is
     * about the repository, so GitChronicle is only a small credit under it ("credit"), never said aloud.
     *
     * @param  array{en: string, ro: string, source: array{label: string, quote: string, url: string|null}}|null  $ending  the AI's last line
     * @return array<string, mixed>
     */
    public static function cta(PresentationFacts $facts, ?array $ending = null): array
    {
        $scene = [
            'key' => 'cta',
            'heading' => null,
            'quote' => null,
            'url' => $facts->shortUrl(),
            'source' => $ending['source'] ?? ['label' => 'GitHub', 'quote' => $facts->shortUrl(), 'url' => $facts->url],
            ...self::spoken(
                'cta',
                $ending['en'] ?? "It's all on GitHub, under {$facts->owner}. Go have a look.",
                $ending['ro'] ?? "Totul e pe GitHub, la {$facts->owner}. Aruncă o privire.",
                text: $facts->shortUrl(),
            ),
        ];

        $scene['en']['credit'] = 'Made with GitChronicle';
        $scene['ro']['credit'] = 'Realizat cu GitChronicle';

        return $scene;
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
