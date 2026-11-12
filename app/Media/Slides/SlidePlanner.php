<?php

namespace App\Media\Slides;

use App\Media\EnglishText;
use App\Media\RomanianText;
use App\Media\SlideDeck;
use App\Models\Document;
use Carbon\CarbonImmutable;

/**
 * Decides which slides a repository gets and what each one says. The slides are the documentation
 * condensed, so a slide exists only when the stored pages have what it needs (nothing is invented):
 *
 * title, what it does, what it offers, the problem, the architecture diagrams, the main modules (an overview
 * and one slide per module page), the flows, technologies, how to run it, the numbers, what changed
 * recently, and the closing. Without the text writer (AI) the slides are built here, from headings, first
 * sentences, lists and tables. The speaker notes carry the fuller text of the page.
 */
final class SlidePlanner
{
    public const MAX_SLIDES = 20;

    public const MAX_BULLETS = 4;

    public const MODULE_BULLETS = 3;

    /**
     * Plan the deck.
     *
     * @param  array<string, mixed>  $facts  PresentationFacts::summary()
     * @param  array<string, mixed>  $source  SlideSource::collect(), each diagram with its drawn image under "image" (or null)
     * @param  list<array<string, mixed>>  $scenes  the scenes of the video script (only the "problem" one is used)
     * @return list<Slide>
     */
    public static function plan(array $facts, array $source, array $scenes, string $locale): array
    {
        $overview = DocText::sections($source['overview'] ?? '');
        $readme = DocText::sections($source['readme'] ?? '');

        $slides = array_values(array_filter([
            self::title($facts, $locale),
            self::what($facts, $source, $overview, $locale),
            self::features($readme, $overview, $locale),
            self::problem($scenes, $locale),
            ...self::diagrams($source['diagrams'] ?? [], $locale),
            self::moduleOverview($source['modules'] ?? [], $locale),
            ...self::modulePages($source['modules'] ?? [], $locale),
            self::technologies($facts, $overview, $locale),
            self::run($facts, $readme, $overview, $locale),
            self::numbers($facts, $source['hotspots'] ?? [], $locale),
            self::recent($source['recent'] ?? null, $locale),
        ]));

        $slides = self::fitCount($slides);
        $slides[] = self::closing($facts, $locale);

        return $slides;
    }

    /**
     * The cover: the name, the description and the facts GitHub knows (stars, licence, main language).
     *
     * @param  array<string, mixed>  $facts
     */
    private static function title(array $facts, string $locale): Slide
    {
        $github = $facts['github'] ?? null;
        $tags = array_values(array_filter([
            ($github['stars'] ?? 0) > 0 ? '★ '.EnglishText::number($github['stars']).' '.__('slides.stars', [], $locale) : null,
            ($facts['license'] ?? null) === null ? null : $facts['license'],
            $github['language'] ?? null,
        ]));

        return new Slide(
            id: 'title',
            layout: 'title',
            headline: RomanianText::clean($facts['name']),
            eyebrow: __('slides.by_owner', ['owner' => $facts['owner']], $locale),
            lead: $facts['description'],
            tags: $tags,
        );
    }

    /**
     * „What it does”: the first sentence of the overview section as the statement.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $overview
     */
    private static function what(array $facts, array $source, array $overview, string $locale): ?Slide
    {
        $section = DocText::find($overview, '/what the project does|ce face|despre|about/u');
        $sentences = $section === null ? [] : DocText::sentences($section);

        if ($sentences === [] && $facts['description'] !== null) {
            $sentences = [$facts['description']];
        }

        // A statement of fewer than five words says nothing, and the overview of a repository without README
        // says so; neither is what the project does.
        if ($sentences === [] || count(preg_split('/\s+/u', $sentences[0]) ?: []) < 5 || preg_match('/has no README|nu are README|no README/iu', $sentences[0]) === 1) {
            return null;
        }

        return new Slide(
            id: 'what',
            layout: 'statement',
            headline: DocText::headline($sentences[0], 150),
            eyebrow: __('slides.what_it_does', [], $locale),
            lead: isset($sentences[1]) && mb_strlen($sentences[1]) <= 200 ? $sentences[1] : null,
            notes: $section === null ? null : mb_substr(DocText::plain($section), 0, 1400),
            source: $section === null ? null : 'overview',
        );
    }

    /**
     * „What it offers”: the features of the README page (or the bullet list of the overview) as up to four cards.
     *
     * @param  array<string, string>  $readme
     * @param  array<string, string>  $overview
     */
    private static function features(array $readme, array $overview, string $locale): ?Slide
    {
        $section = DocText::find($readme, '/^(features|funcționalități|functionalitati)/u')
            ?? DocText::find($overview, '/what the project does|ce face/u');
        $items = $section === null ? [] : DocText::items($section);

        if (count($items) < 2) {
            return null;
        }

        return new Slide(
            id: 'features',
            layout: 'cards',
            headline: __('slides.features', [], $locale),
            bullets: array_map(self::card(...), array_slice($items, 0, self::MAX_BULLETS)),
            notes: mb_substr(DocText::plain($section), 0, 1400),
            source: 'readme',
        );
    }

    /**
     * A list item as a card: "Title: text" when it starts with bold text, else only the text.
     */
    private static function card(string $item): string
    {
        if (preg_match('/\A\*\*(.+?)\*\*[\s:—–-]*(.*)\z/u', $item, $match) === 1 && trim($match[2]) !== '') {
            return DocText::plain($match[1]).': '.DocText::short(DocText::plain($match[2]), 100);
        }

        return DocText::short(DocText::plain($item), 110);
    }

    /**
     * „The problem it solves”: the problem scene of the script, which the AI wrote from the README.
     *
     * @param  list<array<string, mixed>>  $scenes
     */
    private static function problem(array $scenes, string $locale): ?Slide
    {
        foreach ($scenes as $scene) {
            if (($scene['key'] ?? null) === 'problem' && trim((string) ($scene['text'] ?? '')) !== '') {
                return new Slide(
                    id: 'problem',
                    layout: 'statement',
                    headline: DocText::short(RomanianText::clean($scene['text']), 160),
                    eyebrow: __('slides.problem', [], $locale),
                    source: $scene['source']['label'] ?? null,
                );
            }
        }

        return null;
    }

    /**
     * One slide per drawn diagram: the architecture diagrams, then the flows.
     *
     * @param  list<array<string, mixed>>  $diagrams
     * @return list<Slide>
     */
    private static function diagrams(array $diagrams, string $locale): array
    {
        $slides = [];

        foreach ($diagrams as $position => $diagram) {
            if (($diagram['image'] ?? null) === null) {
                continue;
            }

            // The image is drawn four times finer than its natural size: a diagram that would be shown
            // at less than 75% of its natural size has text nobody can read, so it gets no slide.
            $image = $diagram['image'];

            if (SlideDeck::diagramScale($image['width'], $image['height']) * 4 < 0.75) {
                continue;
            }

            if ($diagram['kind'] === 'flow') {
                $explanation = DocText::plain((string) ($diagram['explanation'] ?? ''));
                $sentences = DocText::sentences((string) ($diagram['explanation'] ?? ''));

                $slides[] = new Slide(
                    id: "flow:{$position}",
                    layout: 'diagram',
                    headline: DocText::headline($diagram['title'] ?: __('slides.untitled_flow', [], $locale), 90),
                    eyebrow: __('slides.how_it_works', [], $locale),
                    diagram: $diagram['image'],
                    caption: $sentences === [] ? null : DocText::short($sentences[0], 140),
                    notes: $explanation === '' ? null : mb_substr($explanation, 0, 1400),
                    source: 'diagrams',
                );

                continue;
            }

            $sentences = DocText::sentences((string) ($diagram['caption'] ?? ''));
            $headline = $sentences === [] || mb_strlen($sentences[0]) > 80 ? ($diagram['title'] ?: __('slides.architecture', [], $locale)) : rtrim($sentences[0], '.');
            $slides[] = new Slide(
                id: "diagram:{$position}",
                layout: 'diagram',
                headline: $headline,
                // A headline that only repeats the diagram's name gets the general label above it.
                eyebrow: $headline === $diagram['title'] || $diagram['title'] === '' ? __('slides.architecture', [], $locale) : $diagram['title'],
                diagram: $diagram['image'],
                caption: $diagram['caption'] === null ? null : DocText::short(DocText::plain($diagram['caption']), 140),
                notes: $diagram['caption'] === null ? null : DocText::plain($diagram['caption']),
                source: 'diagrams',
            );
        }

        return $slides;
    }

    /**
     * An overview of the application's biggest modules as cards: the module and what it is responsible for
     * (the Role sentence of its page, when it is short and written by AI), otherwise its size.
     *
     * @param  list<array{path: string, lines: int, files: int, writer: string, markdown: string}>  $modules
     */
    private static function moduleOverview(array $modules, string $locale): ?Slide
    {
        $cards = [];

        foreach ($modules as $module) {
            $role = $module['writer'] === Document::WRITER_AI ? self::role($module['markdown']) : null;
            $text = $role === null ? null : DocText::short(self::clause($role), 110);
            $size = EnglishText::number($module['files']).' '.__('slides.files_short', [], $locale).' · '.EnglishText::number($module['lines']).' '.__('slides.lines_short', [], $locale);

            $cards[] = $module['path'].': '.($text !== null && mb_strlen($text) <= 150 ? $text : $size);
        }

        if (count($cards) < 2) {
            return null;
        }

        return new Slide(
            id: 'modules',
            layout: 'cards',
            headline: __('slides.modules_headline', ['count' => count($cards)], $locale),
            eyebrow: __('slides.modules', [], $locale),
            bullets: array_slice($cards, 0, 6),
            source: 'modules',
        );
    }

    /**
     * One slide per module whose page describes it in prose (written by AI): the headline says what the
     * module is responsible for, the label line has its size, and up to three bullets name its key
     * classes or functions with what each does. A module without such descriptions gets no slide.
     *
     * @param  list<array{path: string, lines: int, files: int, writer: string, markdown: string}>  $modules
     * @return list<Slide>
     */
    private static function modulePages(array $modules, string $locale): array
    {
        $slides = [];

        foreach ($modules as $module) {
            if ($module['writer'] !== Document::WRITER_AI) {
                continue;
            }

            $sections = DocText::sections($module['markdown']);
            $role = self::role($module['markdown']);
            $bullets = [];

            foreach (DocText::items(DocText::find($sections, '/main components|componente principale|componente/u') ?? '') as $item) {
                $bullet = self::component($item);

                if ($bullet !== null) {
                    $bullets[] = $bullet;
                }
            }

            $bullets = array_slice($bullets, 0, self::MODULE_BULLETS);

            if ($role === null || count($bullets) < 2) {
                continue;
            }

            $how = DocText::find($sections, '/how it works|cum funcționează|cum functioneaza/u');
            $notes = [mb_substr(DocText::plain(DocText::find($sections, '/^(role|rol)\z/u') ?? ''), 0, 700)];

            if ($how !== null) {
                $notes[] = mb_substr(DocText::plain($how), 0, 700);
            }

            $slides[] = new Slide(
                id: "module:{$module['path']}",
                layout: 'bullets',
                headline: DocText::headline(self::clause($role), 110),
                eyebrow: __('slides.module', [], $locale).' · '.$module['path'].' · '.EnglishText::number($module['files']).' '.__('slides.files_short', [], $locale).' · '.EnglishText::number($module['lines']).' '.__('slides.lines_short', [], $locale),
                bullets: $bullets,
                notes: implode("\n\n", array_filter($notes)),
                source: "module:{$module['path']}",
            );
        }

        return $slides;
    }

    /**
     * A component of a module page as a bullet: "`Name`: what it does". Items that only name a symbol and
     * its place in a file (no description) give null.
     */
    private static function component(string $item): ?string
    {
        $plain = DocText::plain($item);

        if (preg_match('/\A(`[^`]+`)\s*(\([^)]*\))?\s*[:—–-]\s*(.+)\z/u', $plain, $match) !== 1) {
            return null;
        }

        $description = DocText::short($match[3], 100);

        // "class in `User.php`, lines 14–43" describes where the code is, not what it does.
        if (preg_match('/\blines? \d|\b(class|function|method|interface|trait) in\b/iu', $description) === 1 || mb_strlen($description) < 12) {
            return null;
        }

        return $match[1].': '.$description;
    }

    /**
     * The first sentence of the Role section of a module page.
     */
    private static function role(string $markdown): ?string
    {
        $role = DocText::find(DocText::sections($markdown), '/^(role|rol)\z/u');
        $sentences = $role === null ? [] : DocText::sentences($role);

        return $sentences[0] ?? null;
    }

    /**
     * The part of a sentence after its subject ("The module `app/Http` contains X" → "contains X" is not
     * safe to cut, so only a leading "The module `x` " is taken away).
     */
    private static function clause(string $sentence): string
    {
        $rest = preg_replace('/\A(The )?(module|modulul)\s+`[^`]+`\s+/iu', '', $sentence) ?? $sentence;

        return $rest === $sentence ? $sentence : mb_strtoupper(mb_substr($rest, 0, 1)).mb_substr($rest, 1);
    }

    /**
     * „Technologies”: the languages with their share of the code, and what the manifests show.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, string>  $overview
     */
    private static function technologies(array $facts, array $overview, string $locale): ?Slide
    {
        if ($facts['languages'] === [] && $facts['technologies'] === []) {
            return null;
        }

        $technologies = DocText::find($overview, '/^(technologies|tehnologii)/u');

        return new Slide(
            id: 'technologies',
            layout: 'columns',
            headline: __('slides.technologies_headline', [], $locale),
            eyebrow: __('slides.technologies', [], $locale),
            rows: array_map(fn (array $language): array => [
                'name' => $language['name'],
                'detail' => EnglishText::number($language['lines']).' '.__('slides.lines', [], $locale),
                'share' => $language['percent'],
            ], $facts['languages']),
            tags: array_slice(array_map(fn (string $technology): string => RomanianText::clean($technology), $facts['technologies']), 0, 12),
            notes: $technologies === null ? null : mb_substr(DocText::plain($technologies), 0, 1400),
            source: 'overview',
        );
    }

    /**
     * „How to run it”: the commands of the installation and running sections, else the README's first command.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, string>  $readme
     * @param  array<string, string>  $overview
     */
    private static function run(array $facts, array $readme, array $overview, string $locale): ?Slide
    {
        $commands = [];
        $sections = [
            ...array_filter($readme, fn (string $heading): bool => preg_match('/^(installation|running|run|getting started|quick start|usage|instalare|rulare)/u', $heading) === 1, ARRAY_FILTER_USE_KEY),
            ...array_filter($overview, fn (string $heading): bool => preg_match('/how to run|getting started|cum (se )?rulează|cum rulezi/u', $heading) === 1, ARRAY_FILTER_USE_KEY),
        ];

        foreach ($sections as $text) {
            array_push($commands, ...DocText::commands($text));
        }

        if ($commands === []) {
            $commands = array_filter([$facts['start_command']['command'] ?? null, ...array_column($facts['commands'] ?? [], 'command')]);
        }

        $commands = array_slice(array_values(array_unique(array_filter($commands, fn (string $command): bool => mb_strlen($command) <= 72 && ! str_contains($command, '`')))), 0, 6);

        if ($commands === []) {
            return null;
        }

        $requirements = DocText::items(DocText::find($readme, '/^(requirements|cerințe|cerinte)/u') ?? '');

        return new Slide(
            id: 'run',
            layout: 'code',
            headline: __('slides.run_headline', [], $locale),
            eyebrow: __('slides.run', [], $locale),
            lead: $requirements === [] ? null : __('slides.requirements', [], $locale).': '.DocText::short(implode(', ', array_map(DocText::plain(...), array_slice($requirements, 0, 3))), 120),
            code: $commands,
            source: 'readme',
        );
    }

    /**
     * „The project in numbers”: only the numbers that are known, and the most changed files.
     *
     * @param  array<string, mixed>  $facts
     * @param  list<array{path: string, commit_count: int}>  $hotspots
     */
    private static function numbers(array $facts, array $hotspots, string $locale): ?Slide
    {
        $values = [
            'files' => $facts['stats']['files'],
            'lines' => $facts['stats']['lines'],
            'module_count' => $facts['stats']['modules'],
            'commits' => $facts['history']['commit_count'] ?? 0,
            'contributors' => $facts['history']['contributor_count'] ?? 0,
            'stars' => $facts['github']['stars'] ?? 0,
        ];

        $stats = [];

        foreach (array_filter($values) as $label => $number) {
            $stats[] = ['value' => EnglishText::number($number), 'label' => __("slides.{$label}", [], $locale)];
        }

        if ($stats === []) {
            return null;
        }

        return new Slide(
            id: 'facts',
            layout: 'facts',
            headline: __('slides.facts_headline', [], $locale),
            eyebrow: __('slides.facts', [], $locale),
            stats: $stats,
            rows: array_map(fn (array $hotspot): array => [
                'name' => $hotspot['path'],
                'detail' => trans_choice('slides.commits_n', $hotspot['commit_count'], ['count' => EnglishText::number($hotspot['commit_count'])], $locale),
                'share' => null,
            ], $hotspots),
            source: 'facts',
        );
    }

    /**
     * „What changed recently”: the latest commits, for a repository that has been updated.
     *
     * @param  array{commits: list<array{subject: string, date: string}>}|null  $recent
     */
    private static function recent(?array $recent, string $locale): ?Slide
    {
        if ($recent === null || $recent['commits'] === []) {
            return null;
        }

        $first = CarbonImmutable::parse($recent['commits'][0]['date'])->locale($locale)->translatedFormat('j F Y');

        return new Slide(
            id: 'recent',
            layout: 'bullets',
            headline: __('slides.recent_headline', [], $locale),
            eyebrow: __('slides.recent', [], $locale).' · '.$first,
            bullets: array_map(fn (array $commit): string => DocText::short(RomanianText::clean($commit['subject']), 90), array_slice($recent['commits'], 0, self::MAX_BULLETS + 1)),
            source: 'commits',
        );
    }

    /**
     * The closing: where to find the repository.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function closing(array $facts, string $locale): Slide
    {
        $rows = [['name' => __('slides.github', [], $locale), 'detail' => preg_replace('#\Ahttps?://#', '', $facts['url']), 'share' => null]];
        $homepage = $facts['github']['homepage'] ?? null;

        if ($homepage !== null && preg_match('#\Ahttps?://#i', $homepage) === 1) {
            $rows[] = ['name' => __('slides.website', [], $locale), 'detail' => preg_replace('#\Ahttps?://#', '', rtrim($homepage, '/')), 'share' => null];
        }

        return new Slide(
            id: 'closing',
            layout: 'closing',
            headline: __('slides.closing', [], $locale),
            eyebrow: $facts['owner'].' / '.$facts['name'],
            rows: $rows,
        );
    }

    /**
     * Keep the deck within MAX_SLIDES (one more is added, the closing): the module pages go first, then
     * the flows, then the recent changes.
     *
     * @param  list<Slide>  $slides
     * @return list<Slide>
     */
    private static function fitCount(array $slides): array
    {
        foreach (['module:', 'flow:', 'recent'] as $prefix) {
            while (count($slides) > self::MAX_SLIDES - 1) {
                $last = null;

                foreach ($slides as $position => $slide) {
                    if (str_starts_with($slide->id, $prefix)) {
                        $last = $position;
                    }
                }

                if ($last === null) {
                    break;
                }

                array_splice($slides, $last, 1);
            }
        }

        return $slides;
    }
}
