<?php

namespace App\Media;

use Carbon\CarbonImmutable;

/**
 * Writes the slides of a repository as Marp Markdown, from the same real data as the video: the facts
 * (PresentationFacts::summary()) and the scenes of the script. The slides, in order:
 *
 * the cover → What it does → What makes it special → Architecture (the diagram) → Technologies →
 * Modules → In numbers → History → Get started → the closing. The deck is generated content, so its
 * fixed labels are English, like the documentation (only the video is made in two languages).
 *
 * A slide whose data is not known is left out. Marp cuts what does not fit on a slide, so every list
 * is capped and every text is cut (MarpText::text()). Every value from the repository is escaped;
 * the images (the logo, the diagram) arrive as base64 data URIs, because Marp may not read local files.
 */
final class SlideDeck
{
    /**
     * The biggest size of the diagram on its slide, in pixels (the slide is 1280×720).
     */
    private const DIAGRAM_MAX_WIDTH = 1100;

    private const DIAGRAM_MAX_HEIGHT = 400;

    /**
     * Build the deck.
     *
     * @param  array<string, mixed>  $facts  PresentationFacts::summary()
     * @param  list<array<string, mixed>>  $scenes  the scenes of the script
     * @param  string  $logo  the app's logo as a data URI
     * @param  array{uri: string, width: int, height: int}|null  $diagram  the drawn diagram, when there is one
     * @return array{markdown: string, titles: list<string>} the Markdown and the title of every slide
     */
    public static function build(array $facts, array $scenes, string $logo, ?array $diagram = null): array
    {
        $slides = array_values(array_filter([
            self::cover($facts, $scenes, $logo),
            self::about($facts, $scenes),
            self::features($scenes),
            self::architecture($facts, $diagram),
            self::technologies($facts),
            self::modules($facts),
            self::numbers($facts),
            self::history($facts),
            self::start($facts),
            self::closing($facts, $scenes, $logo),
        ]));

        $frontMatter = implode("\n", [
            '---',
            'marp: true',
            'theme: gitchronicle',
            'lang: en',
            'paginate: true',
            // The document's title (the PDF's metadata): plain text, without anything that looks like markup.
            'title: '.json_encode(preg_replace('/[<>"\\\\]/', '', RomanianText::clean("{$facts['owner']}/{$facts['name']}")).' · GitChronicle', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'header: '.json_encode("![w:44]({$logo})"),
            'footer: '.json_encode(MarpText::text("{$facts['owner']}/{$facts['name']}", 80).' · GitChronicle', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '---',
        ]);

        return [
            'markdown' => $frontMatter."\n\n".implode("\n\n---\n\n", array_column($slides, 'markdown'))."\n",
            'titles' => array_column($slides, 'title'),
        ];
    }

    /**
     * The cover: the logo, the owner, the name and one sentence about the project.
     *
     * @param  array<string, mixed>  $facts
     * @param  list<array<string, mixed>>  $scenes
     * @return array{title: string, markdown: string}
     */
    private static function cover(array $facts, array $scenes, string $logo): array
    {
        $hook = self::scene($scenes, 'hook');

        return self::slide($facts['name'], [
            '<!-- _class: lead -->',
            '<!-- _paginate: false -->',
            '<!-- _header: "" -->',
            '<!-- _footer: "" -->',
            "![w:132]({$logo})",
            '<span class="muted">'.MarpText::text($facts['owner'], 60).' /</span>',
            '# '.MarpText::text($facts['name'], 60),
            MarpText::text($hook['text'] ?? $facts['description'], 180),
        ]);
    }

    /**
     * „Ce face”: the opening line and the problem the project solves (AI), or the description (template).
     *
     * @param  array<string, mixed>  $facts
     * @param  list<array<string, mixed>>  $scenes
     * @return array{title: string, markdown: string}|null
     */
    private static function about(array $facts, array $scenes): ?array
    {
        $problem = self::scene($scenes, 'problem');
        $description = $facts['description'];

        if ($problem === null && $description === null) {
            return null;
        }

        $source = $problem['source']['label'] ?? null;

        return self::slide('What it does', [
            '## What it does',
            $description === null ? null : MarpText::text($description, 320),
            $problem === null ? null : '### The problem it solves',
            $problem === null ? null : '**'.MarpText::text($problem['text'], 190).'**',
            $source === null ? null : '<span class="muted">Source: '.MarpText::text($source, 60).'</span>',
        ]);
    }

    /**
     * „Ce îl face special”: the feature scenes of the script as cards. Without AI there are none.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return array{title: string, markdown: string}|null
     */
    private static function features(array $scenes): ?array
    {
        $features = array_values(array_filter($scenes, fn (array $scene): bool => $scene['key'] === 'feature'));

        if ($features === []) {
            return null;
        }

        $items = [];

        foreach ($features as $position => $feature) {
            $items[] = ($position + 1).'. **'.MarpText::text($feature['title'] ?? $feature['label'], 40).'** '.MarpText::text($feature['text'], 180);
        }

        $sources = array_values(array_unique(array_filter(array_map(fn (array $feature): ?string => $feature['source']['label'] ?? null, $features))));

        return self::slide('What makes it special', [
            '<!-- _class: features columns-'.count($features).' -->',
            '## What makes it special',
            implode("\n", $items),
            $sources === [] ? null : '<span class="muted">Sources: '.MarpText::text(implode(', ', $sources), 80).'</span>',
        ]);
    }

    /**
     * „Arhitectura”: the diagram drawn from the code analysis, as big as it fits.
     *
     * @param  array<string, mixed>  $facts
     * @param  array{uri: string, width: int, height: int}|null  $diagram
     * @return array{title: string, markdown: string}|null
     */
    private static function architecture(array $facts, ?array $diagram): ?array
    {
        if ($diagram === null || $facts['diagram'] === null) {
            return null;
        }

        // Scale the image to the biggest size that fits the slide, keeping its proportions.
        $scale = min(self::DIAGRAM_MAX_WIDTH / $diagram['width'], self::DIAGRAM_MAX_HEIGHT / $diagram['height']);
        $width = (int) floor($diagram['width'] * $scale);

        return self::slide('Architecture', [
            '<!-- _class: diagram -->',
            '## '.MarpText::text($facts['diagram']['title'], 60),
            "![w:{$width}]({$diagram['uri']})",
            $facts['diagram']['caption'] === null ? null : '<span class="muted">'.MarpText::text($facts['diagram']['caption'], 240).'</span>',
        ]);
    }

    /**
     * „Tehnologii”: the languages of the analyzed code and the technologies found in the manifests.
     *
     * @param  array<string, mixed>  $facts
     * @return array{title: string, markdown: string}|null
     */
    private static function technologies(array $facts): ?array
    {
        if ($facts['languages'] === [] && $facts['technologies'] === []) {
            return null;
        }

        $rows = array_map(
            fn (array $language): string => '| '.MarpText::text($language['name'], 30).' | '.EnglishText::number($language['files']).' | '.EnglishText::number($language['lines'])." | {$language['percent']}% |",
            $facts['languages'],
        );

        return self::slide('Technologies', [
            '## Technologies',
            $rows === [] ? null : "| Language | Files | Lines | Share of code |\n|---|---|---|---|\n".implode("\n", $rows),
            $facts['technologies'] === [] ? null : '**Found in the manifests:** '.implode(' · ', array_map(
                fn (string $technology): string => MarpText::text($technology, 30),
                array_slice($facts['technologies'], 0, 10),
            )),
        ]);
    }

    /**
     * „Module”: the biggest modules of the code analysis.
     *
     * @param  array<string, mixed>  $facts
     * @return array{title: string, markdown: string}|null
     */
    private static function modules(array $facts): ?array
    {
        if ($facts['modules'] === []) {
            return null;
        }

        $rows = array_map(
            fn (array $module): string => '| '.MarpText::text($module['path'], 50).' | '.EnglishText::number($module['files']).' | '.EnglishText::number($module['lines']).' |',
            $facts['modules'],
        );

        return self::slide('Modules', [
            '## The largest modules',
            "| Modul | Fișiere | Linii |\n|---|---|---|\n".implode("\n", $rows),
            '<span class="muted">Of the '.EnglishText::count($facts['stats']['modules'], 'module', 'modules').' found by the code analysis; tests, documentation and configuration left out.</span>',
        ]);
    }

    /**
     * „În cifre”: the sizes of the code, the history and the stars — only the numbers that are known.
     *
     * @param  array<string, mixed>  $facts
     * @return array{title: string, markdown: string}|null
     */
    private static function numbers(array $facts): ?array
    {
        $stars = $facts['github']['stars'] ?? 0;

        $numbers = array_filter([
            'files analysed' => $facts['stats']['files'],
            'lines of code' => $facts['stats']['lines'],
            'modules' => $facts['stats']['modules'],
            'commits imported' => $facts['history']['commit_count'] ?? 0,
            'contributors' => $facts['history']['contributor_count'] ?? 0,
            'stars on GitHub' => $stars,
        ]);

        if ($numbers === []) {
            return null;
        }

        $items = [];

        foreach ($numbers as $label => $number) {
            $items[] = '- **'.EnglishText::number($number)."** {$label}";
        }

        return self::slide('In numbers', [
            '<!-- _class: stats -->',
            '## In numbers',
            implode("\n", $items),
        ]);
    }

    /**
     * „Istoric”: when the imported commits start and end, and who wrote the most.
     *
     * @param  array<string, mixed>  $facts
     * @return array{title: string, markdown: string}|null
     */
    private static function history(array $facts): ?array
    {
        $history = $facts['history'];

        if ($history === null) {
            return null;
        }

        $contributors = array_map(fn (string $name): string => MarpText::text($name, 40), $history['contributors']);

        return self::slide('History', [
            '## History',
            implode("\n", array_filter([
                '- First imported commit: **'.self::date($history['first_commit_at']).'**',
                '- Latest commit: **'.self::date($history['last_commit_at']).'**',
                '- **'.EnglishText::count($history['commit_count'], 'commit', 'commits').'** by **'.EnglishText::count($history['contributor_count'], 'contributor', 'contributors').'**',
                $contributors === [] ? null : '- Most commits: '.implode(', ', $contributors),
            ])),
            '<span class="muted">From the repository\'s git history.</span>',
        ]);
    }

    /**
     * „Cum începi”: the README's command and the commands the manifests define.
     *
     * @param  array<string, mixed>  $facts
     * @return array{title: string, markdown: string}|null
     */
    private static function start(array $facts): ?array
    {
        $readmeCommand = $facts['start_command'] === null ? null : MarpText::code($facts['start_command']['command']);
        $others = [];

        foreach ($facts['commands'] as $command) {
            $code = MarpText::code($command['command']);

            if ($code !== null && $code !== $readmeCommand && count($others) < 4) {
                $others[] = "- `{$code}` — ".MarpText::text($command['source'], 40);
            }
        }

        if ($readmeCommand === null && $others === []) {
            return null;
        }

        return self::slide('Get started', [
            '## Get started',
            $readmeCommand === null ? null : "```\n{$readmeCommand}\n```",
            $readmeCommand === null ? null : '<span class="muted">The first command of the README.</span>',
            $others === [] ? null : "### Alte comenzi definite în proiect\n\n".implode("\n", $others),
        ]);
    }

    /**
     * The closing: the invitation, the address and where the deck comes from.
     *
     * @param  array<string, mixed>  $facts
     * @param  list<array<string, mixed>>  $scenes
     * @return array{title: string, markdown: string}
     */
    private static function closing(array $facts, array $scenes, string $logo): array
    {
        $cta = self::scene($scenes, 'cta');
        $commit = $facts['sha'] === null ? '' : ' · commit '.substr($facts['sha'], 0, 7);

        return self::slide('Closing', [
            '<!-- _class: closing -->',
            '<!-- _paginate: false -->',
            '<!-- _header: "" -->',
            '<!-- _footer: "" -->',
            "![w:96]({$logo})",
            '# '.MarpText::text($cta['text'] ?? 'See the project on GitHub.', 80),
            '<span class="url">'.MarpText::text(preg_replace('#\Ahttps?://#', '', $facts['url']), 100).'</span>',
            '<span class="muted">Deck generated by GitChronicle from the repository\'s real data'.$commit.'.</span>',
        ]);
    }

    /**
     * One slide: its title (for the page) and its Markdown blocks, the missing ones (null, '') left out.
     *
     * @param  list<string|null>  $blocks
     * @return array{title: string, markdown: string}
     */
    private static function slide(string $title, array $blocks): array
    {
        return ['title' => $title, 'markdown' => implode("\n\n", array_filter($blocks, fn (?string $block): bool => $block !== null && $block !== ''))];
    }

    /**
     * The first scene of a kind, or null.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return array<string, mixed>|null
     */
    private static function scene(array $scenes, string $key): ?array
    {
        foreach ($scenes as $scene) {
            if ($scene['key'] === $key) {
                return $scene;
            }
        }

        return null;
    }

    /**
     * A date as Romanians write it: „10 noiembrie 2026”.
     */
    private static function date(string $iso): string
    {
        return CarbonImmutable::parse($iso)->setTimezone(config('app.timezone'))->locale('en')->translatedFormat('j F Y');
    }
}
