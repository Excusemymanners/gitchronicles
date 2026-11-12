<?php

namespace Tests\Feature\Media;

use App\Media\SlideDeck;
use Tests\TestCase;

/**
 * The Markdown of the slides: one slide per known fact, in a fixed order, with every text escaped.
 */
class SlideDeckTest extends TestCase
{
    private const LOGO = 'data:image/png;base64,TE9HTw==';

    public function test_a_full_deck_has_every_slide_in_order(): void
    {
        $deck = SlideDeck::build($this->facts(), $this->scenes(), self::LOGO, ['uri' => 'data:image/png;base64,RElBRw==', 'width' => 1296, 'height' => 360]);

        $this->assertSame(
            ['magazin', 'What it does', 'What makes it special', 'Architecture', 'Technologies', 'Modules', 'In numbers', 'History', 'Get started', 'Closing'],
            $deck['titles'],
        );
        $markdown = $deck['markdown'];
        $this->assertStringStartsWith("---\nmarp: true\ntheme: gitchronicle\nlang: en\npaginate: true\n", $markdown);
        $this->assertSame(9, substr_count($markdown, "\n\n---\n\n"));
        $this->assertStringContainsString("# magazin\n\nMagazinul mic care ține minte coșul.", $markdown);
        $this->assertStringContainsString("### The problem it solves\n\n**Cumperi fără să îți faci cont.**\n\n<span class=\"muted\">Source: README.md</span>", $markdown);
        $this->assertStringContainsString("<!-- _class: features columns-2 -->\n\n## What makes it special\n\n1. **Coș care nu uită** Ține minte produsele.\n2. **Plăți fără cont** Plătești cu cardul.", $markdown);
        $this->assertStringContainsString("## Containere\n\n![w:1100](data:image/png;base64,RElBRw==)\n\n<span class=\"muted\">Părțile aplicației.</span>", $markdown);
        $this->assertStringContainsString("| PHP | 9 | 2,800 | 82% |\n| TypeScript | 3 | 600 | 18% |", $markdown);
        $this->assertStringContainsString('**Found in the manifests:** Laravel 13 · React 19', $markdown);
        $this->assertStringContainsString('| src/Models | 2 | 1,400 |', $markdown);
        $this->assertStringContainsString("- **12** files analysed\n- **3,400** lines of code\n- **3** modules\n- **25** commits imported\n- **2** contributors\n- **1,234** stars on GitHub", $markdown);
        $this->assertStringContainsString("- First imported commit: **1 October 2026**\n- Latest commit: **10 November 2026**\n- **25 commits** by **2 contributors**\n- Most commits: Ana, Dan", $markdown);
        $this->assertStringContainsString("```\ncomposer install\n```", $markdown);
        $this->assertStringContainsString('- `composer run test` — composer.json', $markdown);
        $this->assertStringContainsString("# Încearcă magazinul chiar azi.\n\n<span class=\"url\">github.com/acme/magazin</span>", $markdown);
        $this->assertStringContainsString('commit abcdef1.', $markdown);
    }

    public function test_slides_without_data_are_left_out(): void
    {
        $facts = [
            ...$this->facts(),
            'description' => null, 'github' => null, 'languages' => [], 'modules' => [], 'history' => null,
            'start_command' => null, 'commands' => [], 'technologies' => [], 'diagram' => null,
            'stats' => ['files' => 0, 'lines' => 0, 'modules' => 0],
        ];
        $scenes = [['key' => 'hook', 'title' => 'magazin', 'text' => null], ['key' => 'cta', 'text' => 'Vezi proiectul pe GitHub.']];

        $deck = SlideDeck::build($facts, $scenes, self::LOGO);

        $this->assertSame(['magazin', 'Closing'], $deck['titles']);
    }

    public function test_a_diagram_that_could_not_be_drawn_leaves_its_slide_out(): void
    {
        $deck = SlideDeck::build($this->facts(), $this->scenes(), self::LOGO, null);

        $this->assertNotContains('Architecture', $deck['titles']);
    }

    public function test_a_tall_diagram_is_scaled_by_its_height(): void
    {
        $deck = SlideDeck::build($this->facts(), $this->scenes(), self::LOGO, ['uri' => 'data:image/png;base64,RElBRw==', 'width' => 1000, 'height' => 2000]);

        $this->assertStringContainsString('![w:200](data:image/png;base64,RElBRw==)', $deck['markdown']);
    }

    public function test_text_from_the_repository_cannot_restyle_the_deck_or_add_slides(): void
    {
        $facts = [...$this->facts(), 'name' => "mag\n---\n<style>section{display:none}</style>", 'description' => 'a | b <!-- _backgroundColor: red -->'];
        $facts['modules'] = [['path' => 'src|Models', 'files' => 1, 'lines' => 1]];
        $facts['commands'] = [['command' => 'echo `id`', 'source' => 'Makefile']];

        $markdown = SlideDeck::build($facts, [], self::LOGO)['markdown'];

        $this->assertStringNotContainsString('<style>', $markdown);
        $this->assertStringNotContainsString('<!-- _backgroundColor', $markdown);
        $this->assertStringContainsString('a \| b &lt;\!-- \_backgroundColor\: red --\>', $markdown);
        $this->assertStringContainsString('| src\|Models | 1 | 1 |', $markdown);
        $this->assertStringNotContainsString('echo `id`', $markdown);
        $this->assertSame(count(SlideDeck::build($facts, [], self::LOGO)['titles']) - 1, substr_count($markdown, "\n\n---\n\n"));
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        return [
            'owner' => 'acme',
            'name' => 'magazin',
            'url' => 'https://github.com/acme/magazin',
            'sha' => 'abcdef1234567890',
            'description' => 'Un magazin mic, cu coș de cumpărături.',
            'github' => ['stars' => 1234, 'forks' => 1, 'language' => 'PHP', 'license' => 'MIT', 'topics' => [], 'homepage' => null, 'description' => null],
            'license' => 'MIT',
            'languages' => [
                ['name' => 'PHP', 'files' => 9, 'lines' => 2800, 'percent' => 82],
                ['name' => 'TypeScript', 'files' => 3, 'lines' => 600, 'percent' => 18],
            ],
            'modules' => [['path' => 'src/Models', 'files' => 2, 'lines' => 1400]],
            'stats' => ['files' => 12, 'lines' => 3400, 'modules' => 3],
            'history' => [
                'commit_count' => 25, 'contributor_count' => 2,
                'first_commit_at' => '2026-10-01T10:00:00+00:00', 'last_commit_at' => '2026-11-10T09:00:00+00:00',
                'contributors' => ['Ana', 'Dan'],
            ],
            'start_command' => ['command' => 'composer install', 'url' => null],
            'commands' => [['command' => 'composer install', 'source' => 'composer.json'], ['command' => 'composer run test', 'source' => 'composer.json']],
            'technologies' => ['Laravel 13', 'React 19'],
            'diagram' => ['type' => 'container', 'title' => 'Containere', 'caption' => 'Părțile aplicației.', 'mermaid' => 'flowchart LR'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scenes(): array
    {
        $source = ['label' => 'README.md', 'quote' => 'Un magazin mic.', 'url' => null];

        return [
            ['key' => 'hook', 'title' => 'magazin', 'text' => 'Magazinul mic care ține minte coșul.', 'source' => $source],
            ['key' => 'problem', 'text' => 'Cumperi fără să îți faci cont.', 'source' => $source],
            ['key' => 'feature', 'title' => 'Coș care nu uită', 'text' => 'Ține minte produsele.', 'source' => $source],
            ['key' => 'feature', 'title' => 'Plăți fără cont', 'text' => 'Plătești cu cardul.', 'source' => $source],
            ['key' => 'cta', 'text' => 'Încearcă magazinul chiar azi.', 'source' => $source],
        ];
    }
}
