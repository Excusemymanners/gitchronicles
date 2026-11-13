<?php

namespace Tests\Feature;

use App\Documentation\DiagramPng;
use App\Documentation\DocumentVisuals;
use App\Documentation\ReadmeShowcase;
use App\Enums\DiagramType;
use App\Media\GitHubCapture;
use App\Models\Diagram;
use App\Models\Document;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * The pictures of the documentation pages: collected from the README of the clone (ReadmeShowcase), served by
 * DocsPictureController, drawn from the module graph (DocumentVisuals) and packed with the README (the zip).
 * No network: downloads are faked, and the architecture diagram is not drawn (no Chrome in tests).
 */
class DocumentationPicturesTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'abc1234def5678abc1234def5678abc1234def56';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_collects_files_of_the_clone_and_downloads_from_allowed_hosts_only(): void
    {
        Http::fake(['raw.githubusercontent.com/*' => Http::response($this->png(400, 300), 200, ['Content-Type' => 'image/png'])]);
        $repository = $this->repositoryWithReadme(<<<'MD'
# Acme

[![CI](https://img.shields.io/badge/ci-passing-green.svg)](https://example.com)
![Logo](docs/logo.png)
<img src="https://raw.githubusercontent.com/acme/acme/main/shot.png" alt="A screenshot" width="600">
<img src="https://evil.example.net/big.png" alt="Refused host">
![Tiny](docs/tiny.png)
![Outside](../../../.env)

## Features

Fast and small.
MD);
        Storage::disk('local')->put("repositories/{$repository->id}/docs/logo.png", $this->png(300, 200));
        Storage::disk('local')->put("repositories/{$repository->id}/docs/tiny.png", $this->png(40, 40));

        $manifest = app(ReadmeShowcase::class)->collect($repository);

        $this->assertSame(['Logo', 'A screenshot'], array_column($manifest['pictures'], 'alt'));
        $this->assertSame(['readme-1', 'readme-2'], array_column($manifest['pictures'], 'id'));
        $this->assertSame([300, 400], array_column($manifest['pictures'], 'width'));
        $this->assertSame('Features', $manifest['sections'][1]['heading']);
        $this->assertSame(['readme-1', 'readme-2'], $manifest['sections'][0]['pictures']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.net'));
        Storage::disk('local')->assertExists("media/{$repository->id}/readme/".self::SHA.'/readme-1.png');
    }

    public function test_a_picture_with_scripts_is_not_kept(): void
    {
        $repository = $this->repositoryWithReadme("# Acme\n\n![Chart](docs/chart.svg)\n![Good](docs/good.svg)");
        Storage::disk('local')->put("repositories/{$repository->id}/docs/chart.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200"><script>alert(1)</script></svg>');
        Storage::disk('local')->put("repositories/{$repository->id}/docs/good.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200"><rect width="10" height="10"/></svg>');

        $manifest = app(ReadmeShowcase::class)->collect($repository);

        $this->assertSame(['Good'], array_column($manifest['pictures'], 'alt'));
        $this->assertSame('image/svg+xml', $manifest['pictures'][0]['mime']);
    }

    public function test_the_picture_route_serves_a_stored_picture_with_cache_headers_and_refuses_unknown_ids(): void
    {
        $repository = $this->repositoryWithReadme("# Acme\n\n![Logo](docs/logo.png)");
        Storage::disk('local')->put("repositories/{$repository->id}/docs/logo.png", $this->png(300, 200));
        app(ReadmeShowcase::class)->collect($repository);

        $response = $this->get("/repositories/{$repository->id}/docs/pictures/readme-1");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));

        $this->get("/repositories/{$repository->id}/docs/pictures/readme-1", ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        $this->get("/repositories/{$repository->id}/docs/pictures/readme-9")->assertNotFound();
        $this->get("/repositories/{$repository->id}/docs/pictures/github-page")->assertNotFound();
        $this->get("/repositories/{$repository->id}/docs/pictures/..%2F..%2F.env")->assertNotFound();
    }

    public function test_pictures_of_an_older_commit_are_not_shown_after_a_new_commit(): void
    {
        $repository = $this->repositoryWithReadme("# Acme\n\n![Logo](docs/logo.png)");
        Storage::disk('local')->put("repositories/{$repository->id}/docs/logo.png", $this->png(300, 200));
        app(ReadmeShowcase::class)->collect($repository);

        $repository->update(['last_sha' => str_repeat('f', 40)]);

        $this->assertNull(app(ReadmeShowcase::class)->manifest($repository->fresh()));
        $this->get("/repositories/{$repository->id}/docs/pictures/readme-1")->assertNotFound();
    }

    public function test_a_module_page_gets_its_neighbourhood_with_at_most_six_a_side(): void
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => self::SHA]);
        $center = Module::factory()->for($repository)->create(['path' => 'app/Http']);
        Document::factory()->for($repository)->forModule($center)->create(['slug' => 'app-http', 'title' => 'app/Http']);

        foreach (range(1, 8) as $number) {
            $importer = Module::factory()->for($repository)->create(['path' => "client/part{$number}"]);
            ModuleDependency::factory()->for($repository)->create(['from_module_id' => $importer->id, 'to_module_id' => $center->id, 'weight' => 20 - $number]);
        }

        $imported = Module::factory()->for($repository)->create(['path' => 'app/Models']);
        Document::factory()->for($repository)->forModule($imported)->create(['slug' => 'app-models', 'title' => 'app/Models']);
        ModuleDependency::factory()->for($repository)->create(['from_module_id' => $center->id, 'to_module_id' => $imported->id, 'weight' => 5]);

        $visuals = app(DocumentVisuals::class)->for($repository, Document::where('slug', 'app-http')->first());

        $this->assertSame('app/Http', $visuals['neighbourhood']['module']);
        $this->assertCount(6, $visuals['neighbourhood']['incoming']);
        $this->assertSame(2, $visuals['neighbourhood']['more_incoming']);
        $this->assertSame(19, $visuals['neighbourhood']['incoming'][0]['weight']);
        $this->assertNull($visuals['neighbourhood']['incoming'][0]['href']);
        $this->assertSame([['path' => 'app/Models', 'weight' => 5, 'href' => "/repositories/{$repository->id}/docs/app-models"]], $visuals['neighbourhood']['outgoing']);
        $this->assertNull($visuals['facts']);
    }

    public function test_the_docs_page_sends_the_visuals_and_the_readme_archive_has_the_pictures(): void
    {
        $repository = $this->repositoryWithReadme("# Acme\n\n![Logo](docs/logo.png)\n\n## Features\n\nFast.");
        Storage::disk('local')->put("repositories/{$repository->id}/docs/logo.png", $this->png(300, 200));
        app(ReadmeShowcase::class)->collect($repository);
        Document::factory()->for($repository)->overview()->create();
        Document::factory()->for($repository)->readme()->create(['markdown' => "# Acme\n\nIntro.\n\n## How it solves it\n\nWith modules.\n\n## Tests\n\nRun them."]);

        $this->get("/repositories/{$repository->id}/docs")->assertInertia(fn ($page) => $page
            ->where('visuals.commit', self::SHA)
            ->where('visuals.pictures.0.alt', 'Logo')
            ->where('visuals.description.sections.1.heading', 'Features')
            ->where('visuals.description.sections.0.markdown', '![Logo](/repositories/'.$repository->id.'/docs/pictures/readme-1?v=abc1234#300x200)')
            ->where('visuals.gallery', [])
            ->where('visuals.pictures.0.url', "/repositories/{$repository->id}/docs/pictures/readme-1?v=abc1234")
            ->where('visuals.capture', null));

        $response = $this->get("/repositories/{$repository->id}/docs/readme.zip");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $path = tempnam(sys_get_temp_dir(), 'zip-test');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }

        $readme = (string) $zip->getFromName('README.md');
        $zip->close();
        unlink($path);

        sort($names);
        $this->assertSame(['README.md', 'images/readme-1.png'], $names);
        $this->assertStringContainsString('![Logo](images/readme-1.png)', $readme);
        $this->assertStringContainsString("## From the project's own README", $readme);
        $this->assertStringContainsString("*From the repository's README at commit abc1234*", $readme);
        $this->assertStringContainsString('### Features', $readme);
        $this->assertStringContainsString('Fast.', $readme);
        $this->assertStringContainsString('## How it solves it', $readme);
        $this->get("/repositories/{$repository->id}/docs/readme.md")->assertOk();
    }

    public function test_the_architecture_diagram_goes_after_how_it_solves_it_and_the_opening_picture_is_the_capture(): void
    {
        $this->app->instance(DiagramPng::class, new class extends DiagramPng
        {
            public function render(string $mermaid): ?string
            {
                return "\x89PNG\r\n\x1a\nfake";
            }
        });
        $repository = Repository::factory()->ready()->create(['last_sha' => self::SHA]);
        Diagram::factory()->for($repository)->create(['type' => DiagramType::Component, 'key' => 'components', 'mermaid' => 'flowchart LR; A-->B', 'meta' => ['title' => 'Components', 'caption' => 'c']]);
        $folder = Storage::disk('local')->path("media/{$repository->id}/capture/".self::SHA);
        mkdir($folder, 0777, true);
        file_put_contents("{$folder}/page-00.png", $this->png(1296, 1200));
        file_put_contents("{$folder}/landmarks.json", json_encode(['version' => GitHubCapture::VERSION, 'captured_at' => '2026-11-12T11:39:06Z', 'landmarks' => ['name' => ['text' => 'x', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]], 'tiles' => [['file' => 'page-00.png', 'y' => 0, 'height' => 1200]]]));
        Document::factory()->for($repository)->readme()->create(['markdown' => "# Acme\n\nIntro.\n\n## How it solves it\n\nWith modules.\n\n## Tests\n\nRun them."]);

        $zip = $this->get("/repositories/{$repository->id}/docs/readme.zip");
        $path = tempnam(sys_get_temp_dir(), 'zip-test');
        file_put_contents($path, $zip->getContent());
        $archive = new ZipArchive;
        $archive->open($path);
        $readme = (string) $archive->getFromName('README.md');
        $hasPicture = $archive->locateName('images/github-page.png') !== false && $archive->locateName('images/architecture.png') !== false;
        $archive->close();
        unlink($path);

        $this->assertTrue($hasPicture);
        $this->assertLessThan(strpos($readme, '## Tests'), strpos($readme, '![Components](images/architecture.png)'));
        $this->assertGreaterThan(strpos($readme, '## How it solves it'), strpos($readme, '![Components](images/architecture.png)'));
        $this->assertStringContainsString('*The repository on GitHub, captured 2026-11-12*', $readme);
        $this->assertLessThan(strpos($readme, 'Intro.'), strpos($readme, 'images/github-page.png'));

        $picture = $this->get("/repositories/{$repository->id}/docs/pictures/github-page");
        $picture->assertOk();
        $picture->assertHeader('Content-Type', 'image/png');
    }

    private function repositoryWithReadme(string $readme): Repository
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => self::SHA]);
        Storage::disk('local')->put("repositories/{$repository->id}/README.md", $readme);

        return $repository;
    }

    /**
     * The smallest valid PNG header of a picture of this size (readers of the size need only the IHDR chunk).
     */
    private function png(int $width, int $height): string
    {
        $header = pack('NN', $width, $height)."\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }
}
