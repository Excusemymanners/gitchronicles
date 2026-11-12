<?php

namespace Tests\Feature\Media\Slides;

use App\Media\SlideDeck;
use App\Media\Slides\DocText;
use App\Media\Slides\SlideIdentity;
use App\Media\Slides\SlidePlanner;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Which slides exist for which data, what they say, and that no slide but the credit line of the last one
 * carries the name of this application.
 */
class SlidePlannerTest extends TestCase
{
    private const OVERVIEW = "# acme/magazin\n## What the project does\nA small shop that remembers the cart. It needs no account.\n## Technologies\nPHP and Laravel.\n";

    private const README = "# magazin\n## Features\n- **Cart** that never forgets: it keeps the products.\n- **Payments** without an account.\n## Requirements\n- PHP 8.3\n- Node 22\n## Installation\n```bash\ncomposer install\nnpm run build\n```\n";

    private const MODULE = "# Module `src/Models`\n## Role\nThe module `src/Models` holds the shop data. It has 2 files.\n## Main components\n- **[`Cart`](x)** (class): holds the products a visitor wants to buy.\n- **[`Order`](x)** (class): turns a cart into a paid order.\n- **[`Totals`](x)** — class in `Totals.php`, lines 1–60.\n## How it works\nOrders reference carts.\n";

    public function test_a_full_repository_gets_every_slide_in_order(): void
    {
        $slides = SlidePlanner::plan($this->facts(), $this->source(), [['key' => 'problem', 'text' => 'Shops lose carts.']], 'en');

        $this->assertSame(
            ['title', 'what', 'features', 'problem', 'diagram:0', 'flow:1', 'modules', 'module:src/Models', 'module:src/Http', 'technologies', 'run', 'facts', 'recent', 'closing'],
            array_map(fn ($slide): string => $slide->id, $slides),
        );
        $this->assertSame('A small shop that remembers the cart', $slides[1]->headline);
        $this->assertSame('Cart: that never forgets: it keeps the products', $slides[2]->bullets[0]);
        $this->assertSame(['composer install', 'npm run build'], $slides[10]->code);
        $this->assertStringContainsString('Requirements: PHP 8.3, Node 22', $slides[10]->lead);
        $this->assertStringContainsString('Orders reference carts', $slides[7]->notes);
        $this->assertSame(['`Cart`: holds the products a visitor wants to buy', '`Order`: turns a cart into a paid order'], $slides[7]->bullets);
        $this->assertSame('Holds the shop data', $slides[7]->headline);
        $this->assertSame('Module · src/Models · 2 files · 900 lines', $slides[7]->eyebrow);
    }

    public function test_modules_described_by_a_template_get_only_a_card_with_their_size(): void
    {
        $source = $this->source();
        $source['modules'] = array_map(fn (array $module): array => [...$module, 'writer' => 'template'], $source['modules']);

        $slides = SlidePlanner::plan($this->facts(), $source, [], 'en');
        $ids = array_map(fn ($slide): string => $slide->id, $slides);

        $this->assertContains('modules', $ids);
        $this->assertSame([], array_filter($ids, fn (string $id): bool => str_starts_with($id, 'module:')));
        $this->assertSame('src/Models: 2 files · 900 lines', collect($slides)->firstWhere('id', 'modules')->bullets[0]);
    }

    public function test_a_source_lead_in_is_removed_and_text_is_not_cut_mid_sentence(): void
    {
        $this->assertSame('GitChronicle documents repositories.', DocText::withoutLeadIn('According to README.md, GitChronicle documents repositories.'));
        $this->assertSame('GitChronicle este un asistent.', DocText::withoutLeadIn('Conform README.md, GitChronicle este un asistent.'));
        $this->assertSame('Keeps the cart', DocText::short('Keeps the cart', 40));
        $this->assertSame('Keeps the cart between visits', DocText::short('Keeps the cart between visits. It never forgets the products a visitor chose last week.', 40));
        $long = 'This sentence has no full stop until the very end and nothing that could cut it in a good place at all';
        $this->assertSame($long, DocText::short($long, 40));
        $this->assertStringNotContainsString('…', DocText::short(str_repeat('word ', 80), 40));
    }

    public function test_slides_without_data_are_left_out(): void
    {
        $facts = [...$this->facts(), 'description' => null, 'github' => null, 'license' => null, 'languages' => [], 'technologies' => [], 'history' => null, 'start_command' => null, 'commands' => [], 'stats' => ['files' => 0, 'lines' => 0, 'modules' => 0]];
        $source = ['overview' => null, 'readme' => null, 'modules' => [], 'diagrams' => [], 'hotspots' => [], 'recent' => null];

        $slides = SlidePlanner::plan($facts, $source, [], 'en');

        $this->assertSame(['title', 'closing'], array_map(fn ($slide): string => $slide->id, $slides));
    }

    public function test_a_repository_without_a_readme_has_no_what_it_does_slide(): void
    {
        $source = [...$this->source(), 'overview' => "## What the project does\nThe repository has no README in its root folder, so its files do not say what the project does.\n"];

        $ids = array_map(fn ($slide): string => $slide->id, SlidePlanner::plan($this->facts(), $source, [], 'en'));

        $this->assertNotContains('what', $ids);
    }

    public function test_a_diagram_that_could_not_be_drawn_or_is_unreadable_gets_no_slide(): void
    {
        $source = $this->source();
        $source['diagrams'][0]['image'] = null;
        $source['diagrams'][1]['image'] = ['uri' => 'data:image/png;base64,AA==', 'width' => 400, 'height' => 6000];

        $ids = array_map(fn ($slide): string => $slide->id, SlidePlanner::plan($this->facts(), $source, [], 'en'));

        $this->assertNotContains('diagram:0', $ids);
        $this->assertNotContains('flow:1', $ids);
        $this->assertContains('modules', $ids);
    }

    public function test_a_diagram_fills_its_room_in_the_direction_that_binds(): void
    {
        foreach ([[3200, 1200], [800, 2400], [1000, 1000], [4000, 600]] as [$width, $height]) {
            [$boxWidth, $boxHeight] = SlideDeck::diagramBox($width, $height);
            $scale = SlideDeck::diagramScale($width, $height);

            $this->assertGreaterThanOrEqual(0.8, max($width * $scale / $boxWidth, $height * $scale / $boxHeight), "{$width}x{$height}");
            $this->assertLessThanOrEqual(1.0001, $width * $scale / $boxWidth);
            $this->assertLessThanOrEqual(1.0001, $height * $scale / $boxHeight);
        }

        $this->assertTrue(SlideDeck::diagramBox(800, 2400)[2]);
        $this->assertFalse(SlideDeck::diagramBox(3200, 1200)[2]);
    }

    public function test_the_deck_never_has_more_than_twenty_slides(): void
    {
        $source = $this->source();
        $source['modules'] = array_map(fn (int $number): array => ['path' => "src/M{$number}", 'lines' => 10, 'files' => 1, 'writer' => 'ai', 'markdown' => str_replace('src/Models', "src/M{$number}", self::MODULE)], range(1, 30));

        $slides = SlidePlanner::plan($this->facts(), $source, [], 'en');

        $this->assertLessThanOrEqual(SlidePlanner::MAX_SLIDES, count($slides));
        $this->assertSame('closing', end($slides)->id);
    }

    public function test_the_fixed_labels_follow_the_language(): void
    {
        $slides = SlidePlanner::plan($this->facts(), $this->source(), [], 'ro');
        $technologies = collect($slides)->firstWhere('id', 'technologies');

        $this->assertSame('Tehnologii', $technologies->eyebrow);
        $this->assertSame('de acme', $slides[0]->eyebrow);
    }

    public function test_only_the_credit_line_names_the_application_and_only_on_the_last_slide(): void
    {
        $slides = SlidePlanner::plan($this->facts(), $this->source(), [], 'en');
        $identity = ['avatar' => 'data:image/png;base64,AA==', 'logo' => ['uri' => 'data:image/png;base64,AA==', 'ratio' => 1.0], 'accent' => '#336699', 'accent_source' => 'logo'];

        $deck = SlideDeck::build($slides, $identity, $this->facts(), 'en', CarbonImmutable::parse('2026-11-12'));
        $parts = explode("\n\n---\n\n", $deck['markdown']);
        $last = array_pop($parts);

        $this->assertStringNotContainsStringIgnoringCase('gitchronicle', implode("\n", $parts));
        $this->assertStringNotContainsStringIgnoringCase('gitchronicle', explode('---', $deck['markdown'])[1]);
        $this->assertSame(1, substr_count(strtolower($last), 'gitchronicle'));
        $this->assertStringContainsString('<p class="credit">Generated with GitChronicle · 12 November 2026</p>', $last);
        $this->assertStringContainsString('footer: "acme/magazin"', $deck['markdown']);
        $this->assertStringContainsString('--accent-ink:', $deck['markdown']);
    }

    public function test_values_from_the_repository_are_escaped(): void
    {
        $facts = [...$this->facts(), 'name' => 'x<script>alert(1)</script>', 'description' => 'a "b" <b>c</b>'];
        $slides = SlidePlanner::plan($facts, ['overview' => null, 'readme' => null, 'modules' => [], 'diagrams' => [], 'hotspots' => [], 'recent' => null], [], 'en');
        $identity = ['avatar' => null, 'logo' => null, 'accent' => '#336699', 'accent_source' => 'name'];

        $markdown = SlideDeck::build($slides, $identity, $facts, 'en', CarbonImmutable::now())['markdown'];

        $this->assertStringNotContainsString('<script>', $markdown);
        $this->assertStringNotContainsString('<b>c</b>', $markdown);
    }

    public function test_the_logo_of_a_readme_is_the_first_image_that_is_not_a_badge(): void
    {
        $readme = "[![build](https://img.shields.io/x.svg)](y)\n<p align=\"center\"><img src=\"docs/logo.png\" alt=\"Acme logo\"></p>\n![screenshot](docs/shot.png)\n";

        $this->assertSame(['docs/logo.png', 'docs/shot.png'], SlideIdentity::logoCandidates($readme));
        $this->assertSame([], SlideIdentity::logoCandidates("# Title\n![badge](https://travis-ci.org/a/b.svg)\n"));
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        return [
            'owner' => 'acme', 'name' => 'magazin', 'url' => 'https://github.com/acme/magazin', 'sha' => 'abcdef1234567890',
            'description' => 'A small shop.',
            'github' => ['stars' => 1234, 'forks' => 1, 'language' => 'PHP', 'license' => 'MIT', 'topics' => [], 'homepage' => 'https://shop.example', 'description' => null],
            'license' => 'MIT',
            'languages' => [['name' => 'PHP', 'files' => 9, 'lines' => 2800, 'percent' => 82]],
            'modules' => [], 'stats' => ['files' => 12, 'lines' => 3400, 'modules' => 3],
            'history' => ['commit_count' => 25, 'contributor_count' => 2, 'first_commit_at' => '2026-10-01T10:00:00+00:00', 'last_commit_at' => '2026-11-10T09:00:00+00:00', 'contributors' => ['Ana']],
            'start_command' => null, 'commands' => [], 'technologies' => ['Laravel 13', 'React 19'], 'diagram' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(): array
    {
        $image = ['uri' => 'data:image/png;base64,AA==', 'width' => 3200, 'height' => 1200];

        return [
            'overview' => self::OVERVIEW,
            'readme' => self::README,
            'modules' => [
                ['path' => 'src/Models', 'lines' => 900, 'files' => 2, 'writer' => 'ai', 'markdown' => self::MODULE],
                ['path' => 'src/Http', 'lines' => 500, 'files' => 3, 'writer' => 'ai', 'markdown' => str_replace('src/Models', 'src/Http', self::MODULE)],
            ],
            'diagrams' => [
                ['kind' => 'architecture', 'title' => 'Containers', 'caption' => 'The parts that run separately.', 'explanation' => null, 'mermaid' => 'flowchart LR', 'image' => $image],
                ['kind' => 'flow', 'title' => 'Adding to the cart', 'caption' => null, 'explanation' => 'The visitor clicks. The cart saves it.', 'mermaid' => 'flowchart LR', 'image' => $image],
            ],
            'hotspots' => [['path' => 'src/Cart.php', 'commit_count' => 7]],
            'recent' => ['commits' => [['subject' => 'Fix the cart total', 'date' => '2026-11-10']]],
        ];
    }
}
