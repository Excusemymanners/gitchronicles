<?php

namespace Tests\Feature\Media;

use App\Enums\DiagramType;
use App\Media\FactsCollector;
use App\Models\Commit;
use App\Models\Diagram;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAnalyzedRepositories;
use Tests\TestCase;

/**
 * The real facts gathered for the video and the slides: the README, the generated overview, GitHub,
 * the code analysis, the history and the diagrams. What is not known stays null or empty.
 */
class FactsCollectorTest extends TestCase
{
    use CreatesAnalyzedRepositories;
    use RefreshDatabase;

    private const README = <<<'MARKDOWN'
        ![logo](logo.png)
        [![build](https://img.shields.io/badge/build-ok-green)](https://ci.example)

        # Magazin

        Un magazin mic, cu coş de cumpărături şi plăţi online.

        ## Instalare

        ```bash
        # prima dată
        $ composer install   # aduce pachetele
        php artisan migrate
        ```
        MARKDOWN;

    public function test_it_gathers_the_facts_of_an_analyzed_repository(): void
    {
        Http::fake(['api.github.com/repos/acme/magazin' => Http::response([
            'stargazers_count' => 42, 'forks_count' => 3, 'language' => 'PHP', 'license' => ['spdx_id' => 'Apache-2.0'],
            'topics' => ['shop'], 'homepage' => null, 'description' => 'Magazin online mic.',
        ])]);
        ['repository' => $repository, 'root' => $root] = $this->shopRepository();
        Storage::disk('local')->put("repositories/{$repository->id}/README.md", self::README);
        $this->addFile($this->addModule($repository, 'tests/Feature'), 'tests/Feature/ShopTest.php', str_repeat("<?php\n", 50));
        Document::factory()->overview()->for($repository)->create();
        Diagram::factory()->for($repository)->create([
            'type' => DiagramType::Container,
            'mermaid' => "flowchart LR\n  a --> b\n",
            'meta' => ['title' => 'Containere', 'caption' => 'Părțile aplicației.', 'technologies' => [['name' => 'Laravel 13', 'icon' => null, 'source' => 'composer.json']]],
        ]);
        Commit::factory()->for($repository)->create(['author_name' => 'Ana', 'authored_at' => '2026-10-01 10:00:00', 'parent_sha' => null]);
        Commit::factory()->for($repository)->count(2)->create(['author_name' => 'Dan', 'authored_at' => '2026-11-02 10:00:00']);

        $facts = app(FactsCollector::class)->collect($repository);

        $this->assertSame('acme/magazin', $facts->fullName());
        $this->assertSame('github.com/acme/magazin', $facts->shortUrl());
        $this->assertSame(str_repeat('a', 40), $facts->sha);
        $this->assertSame('Magazin online mic.', $facts->description);
        $this->assertSame(['README.md', 'Despre proiect', 'GitHub'], array_keys($facts->sources));
        $this->assertStringNotContainsString('img.shields.io', $facts->sources['README.md']['text']);
        $this->assertStringContainsString('coș de cumpărături și plăți online', $facts->sources['README.md']['text']);
        $this->assertSame('https://github.com/acme/magazin/blob/'.str_repeat('a', 40).'/README.md', $facts->sources['README.md']['url']);
        $this->assertSame("/repositories/{$repository->id}/docs", $facts->sources['Despre proiect']['url']);
        $this->assertStringContainsString("Main language: PHP\nLicense: Apache-2.0\nTopics: shop", $facts->sources['GitHub']['text']);
        // The star count changes daily: it must not be part of the text that decides a new AI call or a new render.
        $this->assertStringNotContainsString('42', $facts->sources['GitHub']['text']);
        $this->assertSame(42, $facts->github['stars']);
        $this->assertSame('Apache-2.0', $facts->license);
        $this->assertSame('PHP', $facts->languages[0]['name']);
        $this->assertEqualsWithDelta(100, array_sum(array_column($facts->languages, 'percent')), 2);
        $this->assertSame(['src/Models', 'src/Http'], array_column($facts->modules, 'path'));
        $this->assertSame(['files' => 6, 'lines' => $repository->files()->sum('lines'), 'modules' => 4], $facts->stats);
        $this->assertSame(3, $facts->history['commit_count']);
        $this->assertSame(2, $facts->history['contributor_count']);
        $this->assertSame(['Dan', 'Ana'], $facts->history['contributors']);
        $this->assertSame('composer install', $facts->startCommand['command']);
        $this->assertContains(['command' => 'composer run test', 'source' => 'composer.json'], $facts->commands);
        $this->assertSame(['Laravel 13'], $facts->technologies);
        $this->assertSame(['type' => 'container', 'title' => 'Containere', 'caption' => 'Părțile aplicației.', 'mermaid' => "flowchart LR\n  a --> b\n"], $facts->diagram);
        $this->assertArrayNotHasKey('sources', $facts->summary());
    }

    public function test_without_github_and_without_a_readme_the_unknown_facts_stay_empty(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 500)]);
        $repository = $this->analyzedRepository(['description' => null]);

        $facts = app(FactsCollector::class)->collect($repository);

        $this->assertNull($facts->github);
        $this->assertNull($facts->description);
        $this->assertNull($facts->license);
        $this->assertSame([], $facts->sources);
        $this->assertSame('', $facts->aiContext());
        $this->assertSame([], $facts->languages);
        $this->assertSame([], $facts->modules);
        $this->assertNull($facts->history);
        $this->assertNull($facts->startCommand);
        $this->assertNull($facts->diagram);
        $this->assertSame(['files' => 0, 'lines' => 0, 'modules' => 0], $facts->stats);
    }

    public function test_without_github_the_description_and_the_license_come_from_the_clone(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        ['repository' => $repository] = $this->shopRepository();
        $repository->update(['description' => 'Un magazin mic, folosit ca exemplu.']);

        $facts = app(FactsCollector::class)->collect($repository);

        $this->assertSame('Un magazin mic, folosit ca exemplu.', $facts->description);
        $this->assertSame('MIT', $facts->license);
        $this->assertSame(['README.md'], array_keys($facts->sources));
        $this->assertNull($facts->startCommand);
    }

    public function test_a_quote_is_found_in_its_source_whatever_its_spaces_case_or_emphasis(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        ['repository' => $repository] = $this->shopRepository();
        Storage::disk('local')->put("repositories/{$repository->id}/README.md", "# Magazin\n\nUn magazin **mic**, cu `coş`\nde cumpărături.\n");

        $facts = app(FactsCollector::class)->collect($repository);

        $this->assertSame('README.md', $facts->findSource('un magazin mic, cu coș de   cumpărături')['label']);
        $this->assertNull($facts->findSource('Un magazin mare, cu coș de cumpărături'));
        $this->assertNull($facts->findSource('magazin'));
        $this->assertTrue($facts->readmeContains('Un magazin **mic**'));
        $this->assertFalse($facts->readmeContains('npm install'));
    }
}
