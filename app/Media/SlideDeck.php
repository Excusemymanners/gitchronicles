<?php

namespace App\Media;

use App\Media\Slides\Slide;
use App\Media\Slides\SlidePalette;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Writes the slides of a repository as Marp Markdown. Every slide is a few lines of HTML in one of the
 * layouts of the theme (renderers/slides/theme.css); the colours come from the repository (SlidePalette),
 * and the only trace of GitChronicle is the credit line on the closing slide.
 *
 * Every value is escaped, and the images (the avatar, the logo, the diagrams) arrive as data URIs,
 * because Marp may not read local files. A slide's speaker notes are an HTML comment after its content.
 */
final class SlideDeck
{
    /**
     * The room of a wide diagram (heading above, caption below) and of a tall one (heading beside it), in
     * pixels; the slide is 1280×720. A diagram fills the room in the direction that binds.
     */
    private const WIDE_BOX = [1152, 470];

    private const TALL_BOX = [720, 590];

    /**
     * Height divided by width above which a diagram is shown in the tall layout.
     */
    private const TALL_RATIO = 0.85;

    /**
     * The room a diagram of this size gets and whether it is shown beside its heading.
     *
     * @return array{int, int, bool} width, height, beside
     */
    public static function diagramBox(int $width, int $height): array
    {
        $beside = $height / max(1, $width) > self::TALL_RATIO;

        return $beside ? [...self::TALL_BOX, true] : [...self::WIDE_BOX, false];
    }

    /**
     * How much a diagram of this size is scaled to fill its room (1 = its pixels as they are).
     */
    public static function diagramScale(int $width, int $height): float
    {
        [$boxWidth, $boxHeight] = self::diagramBox($width, $height);

        return min($boxWidth / max(1, $width), $boxHeight / max(1, $height));
    }

    /**
     * Build the deck.
     *
     * @param  list<Slide>  $slides
     * @param  array{avatar: ?string, logo: ?array{uri: string, ratio: float}, accent: string, accent_source: string}  $identity
     * @param  array<string, mixed>  $facts  PresentationFacts::summary()
     * @return array{markdown: string, titles: list<string>}
     */
    public static function build(array $slides, array $identity, array $facts, string $locale, CarbonImmutable $date): array
    {
        $palette = SlidePalette::fromAccent($identity['accent']);
        $name = RomanianText::clean("{$facts['owner']}/{$facts['name']}");

        $frontMatter = implode("\n", [
            '---',
            'marp: true',
            'theme: repository',
            'lang: '.$locale,
            'paginate: true',
            'title: '.json_encode(preg_replace('/[<>"\\\\]/', '', $name), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'footer: '.json_encode(MarpText::text($name, 80), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'style: |',
            '  section {',
            ...array_map(fn (string $token, string $color): string => '    --'.strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $token) ?? $token).": {$color};", array_keys($palette), $palette),
            '  }',
            '---',
        ]);

        $markdown = array_map(fn (Slide $slide): string => self::slide($slide, $identity, $locale, $date), $slides);

        return [
            'markdown' => $frontMatter."\n\n".implode("\n\n---\n\n", $markdown)."\n",
            'titles' => array_map(fn (Slide $slide): string => $slide->headline, $slides),
        ];
    }

    /**
     * The Markdown of one slide: its layout class, its content and its speaker notes.
     *
     * @param  array{avatar: ?string, logo: ?array{uri: string, ratio: float}, accent: string, accent_source: string}  $identity
     */
    private static function slide(Slide $slide, array $identity, string $locale, CarbonImmutable $date): string
    {
        $blocks = [
            self::directives($slide),
            $slide->eyebrow === null || $slide->layout === 'diagram' ? null : '<p class="eyebrow">'.self::e($slide->eyebrow).'</p>',
            match ($slide->layout) {
                'title' => self::title($slide, $identity),
                'statement' => self::statement($slide),
                'bullets' => self::bullets($slide),
                'cards' => self::cards($slide),
                'diagram' => self::diagram($slide),
                'columns' => self::columns($slide, $locale),
                'code' => self::code($slide),
                'facts' => self::facts($slide, $locale),
                default => self::closing($slide, $locale, $date),
            },
            self::notes($slide->notes),
        ];

        return implode("\n\n", array_filter($blocks, fn (?string $block): bool => $block !== null && $block !== ''));
    }

    private static function directives(Slide $slide): string
    {
        $lines = ['<!-- _class: '.implode(' ', array_filter([$slide->layout, self::size($slide)])).' -->'];

        if (in_array($slide->layout, ['title', 'closing'], true)) {
            $lines[] = '<!-- _paginate: false -->';
            $lines[] = '<!-- _footer: "" -->';
        }

        return implode("\n", $lines);
    }

    /**
     * The type step of a slide: a short slide gets the larger one ("roomy") and a long statement the
     * smaller ones ("long", "xlong"), so no slide is two lines at the top of an empty page and no text
     * has to be cut to fit.
     */
    private static function size(Slide $slide): ?string
    {
        $length = array_sum(array_map(mb_strlen(...), $slide->bullets));

        return match ($slide->layout) {
            'statement' => mb_strlen($slide->headline) > 200 ? 'xlong' : (mb_strlen($slide->headline) > 110 ? 'long' : null),
            'bullets', 'cards' => $length <= 230 && count($slide->bullets) <= 3 ? 'roomy' : null,
            'code' => count($slide->code) <= 3 ? 'roomy' : null,
            'title' => mb_strlen((string) $slide->lead) > 150 ? 'long' : null,
            'diagram' => self::diagramBox($slide->diagram['width'], $slide->diagram['height'])[2] ? 'beside' : null,
            default => null,
        };
    }

    /**
     * @param  array{avatar: ?string, logo: ?array{uri: string, ratio: float}, accent: string, accent_source: string}  $identity
     */
    private static function title(Slide $slide, array $identity): string
    {
        $marks = array_filter([
            $identity['avatar'] === null ? null : '<img class="avatar" alt="" src="'.$identity['avatar'].'">',
            $identity['logo'] === null ? null : '<span class="logo"><img alt="" src="'.$identity['logo']['uri'].'" style="'.self::logoSize($identity['logo']['ratio']).'"></span>',
        ]);

        return implode("\n", array_filter([
            $marks === [] ? null : '<div class="marks">'.implode('', $marks).'</div>',
            '<h1>'.self::heading($slide->headline).'</h1>',
            $slide->lead === null ? null : '<p class="lead">'.self::e($slide->lead).'</p>',
            $slide->tags === [] ? null : self::tags($slide->tags),
        ]));
    }

    /**
     * The size of the logo in its tile: at most 300×120, whatever its proportions.
     */
    private static function logoSize(float $ratio): string
    {
        $width = (int) round(min(300, 120 * $ratio));

        return 'width:'.$width.'px;height:'.(int) round($width / max($ratio, 0.01)).'px';
    }

    private static function statement(Slide $slide): string
    {
        return implode("\n", array_filter([
            '<h2>'.self::heading($slide->headline).'</h2>',
            $slide->lead === null ? null : '<p class="lead">'.self::e($slide->lead).'</p>',
        ]));
    }

    private static function bullets(Slide $slide): string
    {
        return '<h2>'.self::heading($slide->headline)."</h2>\n".'<ul>'.implode('', array_map(fn (string $bullet): string => '<li>'.self::inline($bullet).'</li>', $slide->bullets)).'</ul>';
    }

    private static function cards(Slide $slide): string
    {
        $cards = array_map(function (string $card): string {
            [$title, $text] = str_contains($card, ': ') && mb_strpos($card, ': ') < 40 ? explode(': ', $card, 2) : [null, $card];

            return '<li>'.($title === null ? '' : '<strong>'.self::inline($title).'</strong>').'<span>'.self::inline($text).'</span></li>';
        }, $slide->bullets);

        return '<h2>'.self::heading($slide->headline)."</h2>\n".'<ul class="grid grid-'.count($cards).'">'.implode('', $cards).'</ul>';
    }

    private static function diagram(Slide $slide): string
    {
        $image = $slide->diagram;
        $scale = self::diagramScale($image['width'], $image['height']);
        $style = 'width:'.(int) floor($image['width'] * $scale).'px;height:'.(int) floor($image['height'] * $scale).'px';
        $text = implode('', array_filter([
            $slide->eyebrow === null ? null : '<p class="eyebrow">'.self::e($slide->eyebrow).'</p>',
            '<h2>'.self::heading($slide->headline).'</h2>',
            $slide->caption === null ? null : '<p class="caption">'.self::e($slide->caption).'</p>',
        ]));
        $figure = '<figure><img alt="" src="'.$image['uri'].'" style="'.$style.'"></figure>';

        return "<div class=\"text\">{$text}</div>\n{$figure}";
    }

    private static function columns(Slide $slide, string $locale): string
    {
        $bars = array_map(fn (array $row): string => '<li><span class="name">'.self::e($row['name']).'</span><span class="detail">'.self::e($row['detail']).'</span>'
            .($row['share'] === null ? '' : '<span class="bar"><i style="width:'.max(2, min(100, (int) $row['share'])).'%"></i></span><span class="share">'.(int) $row['share'].'%</span>').'</li>', $slide->rows);

        return implode("\n", array_filter([
            '<h2>'.self::heading($slide->headline).'</h2>',
            '<div class="cols">'
                .($bars === [] ? '' : '<div><h3>'.self::e(__('slides.languages', [], $locale)).'</h3><ul class="bars">'.implode('', $bars).'</ul></div>')
                .($slide->tags === [] ? '' : '<div><h3>'.self::e(__('slides.built_with', [], $locale)).'</h3>'.self::tags($slide->tags).'</div>')
                .'</div>',
        ]));
    }

    private static function code(Slide $slide): string
    {
        return implode("\n", array_filter([
            '<h2>'.self::heading($slide->headline).'</h2>',
            '<pre><code>'.implode("\n", array_map(fn (string $line): string => self::e($line), $slide->code)).'</code></pre>',
            $slide->lead === null ? null : '<p class="caption">'.self::e($slide->lead).'</p>',
        ]));
    }

    private static function facts(Slide $slide, string $locale): string
    {
        $stats = array_map(fn (array $stat): string => '<li><strong>'.self::e($stat['value']).'</strong><span>'.self::e($stat['label']).'</span></li>', $slide->stats);
        $files = array_map(fn (array $row): string => '<li><code>'.self::e(Str::limit($row['name'], 44)).'</code><span>'.self::e($row['detail']).'</span></li>', $slide->rows);

        return implode("\n", array_filter([
            '<h2>'.self::heading($slide->headline).'</h2>',
            '<div class="cols"><ul class="stats">'.implode('', $stats).'</ul>'
                .($files === [] ? '' : '<div><h3>'.self::e(__('slides.most_changed', [], $locale)).'</h3><ul class="files">'.implode('', $files).'</ul></div>')
                .'</div>',
        ]));
    }

    private static function closing(Slide $slide, string $locale, CarbonImmutable $date): string
    {
        $links = array_map(fn (array $row): string => '<li><span>'.self::e($row['name']).'</span><strong>'.self::e($row['detail']).'</strong></li>', $slide->rows);

        return implode("\n", [
            '<h1>'.self::heading($slide->headline).'</h1>',
            '<ul class="links">'.implode('', $links).'</ul>',
            '<p class="credit">'.self::e(__('slides.credit', ['date' => $date->locale($locale)->translatedFormat('j F Y')], $locale)).'</p>',
        ]);
    }

    /**
     * The speaker notes: an HTML comment, which Marp turns into the notes of the slide (and of the PPTX).
     */
    private static function notes(?string $notes): ?string
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        $text = str_replace(['--', '>', '<', ':'], ['—', '›', '‹', '∶'], trim($notes));

        return "<!--\n{$text}\n-->";
    }

    /**
     * A tag list.
     *
     * @param  list<string>  $tags
     */
    private static function tags(array $tags): string
    {
        return '<ul class="tags">'.implode('', array_map(fn (string $tag): string => '<li>'.self::e($tag).'</li>', $tags)).'</ul>';
    }

    /**
     * A headline for a Markdown heading: escaped, on one line.
     */
    private static function heading(string $text): string
    {
        return self::inline($text);
    }

    /**
     * Text for an HTML element: escaped, with `code` between backticks shown as code.
     */
    private static function inline(string $text): string
    {
        return preg_replace('/`([^`]+)`/u', '<code>$1</code>', self::e($text)) ?? '';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars(preg_replace('/\s+/u', ' ', RomanianText::clean($text)) ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
