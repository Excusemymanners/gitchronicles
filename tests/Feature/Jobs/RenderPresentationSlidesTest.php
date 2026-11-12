<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RenderPresentationSlides;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMediaTools;
use Tests\TestCase;

/**
 * The slides job and its renderer, with the diagram script and Marp faked (FakesMediaTools).
 */
class RenderPresentationSlidesTest extends TestCase
{
    use FakesMediaTools;
    use RefreshDatabase;

    public function test_it_renders_the_pdf_and_the_pptx_and_saves_the_result(): void
    {
        $this->fakeMediaTools();
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);
        $this->travelTo('2026-11-10 12:00:00');

        RenderPresentationSlides::dispatch($repository, $this->facts(), $this->scenes(), 'abc123');

        $directory = "media/{$repository->id}";
        $this->assertDatabaseHas('media_assets', [
            'repository_id' => $repository->id,
            'kind' => 'slides',
            'status' => 'ready',
            'path' => "{$directory}/slides.pdf",
            'source_sha' => 'abc123',
            'duration_ms' => null,
            'size_bytes' => 6,
            'error' => null,
            'generated_at' => '2026-11-10 12:00:00',
        ]);
        $asset = MediaAsset::sole();
        $this->assertSame(['pdf' => "{$directory}/slides.pdf", 'pptx' => "{$directory}/slides.pptx"], $asset->meta['files']);
        $this->assertSame(['magazin', 'What it does', 'Architecture', 'In numbers', 'Closing'], $asset->meta['slides']);
        Storage::disk('local')->assertExists(["{$directory}/slides.pdf", "{$directory}/slides.pptx"]);
        Storage::disk('local')->assertMissing("{$directory}/work-slides");
        $this->assertSame(['diagram', 'marp', 'marp'], array_map($this->toolOf(...), $this->toolCommands));
    }

    public function test_marp_gets_a_self_contained_deck_and_safe_options(): void
    {
        $this->fakeMediaTools();
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);

        RenderPresentationSlides::dispatch($repository, $this->facts(), $this->scenes(), 'abc123');

        [$pdf, $pptx] = $this->commandsOf('marp');
        $this->assertSame('--no-stdin', $pdf[2]);
        $this->assertStringEndsWith('/deck.md', $pdf[3]);
        $this->assertLessThan(array_search('--theme-set', $pdf, true), array_search($pdf[3], $pdf, true));
        $this->assertContains('--no-config-file', $pdf);
        $this->assertNotContains('--allow-local-files', $pdf);
        $this->assertNotContains('--html', $pdf);
        $this->assertSame('/usr/bin/google-chrome', $this->argumentAfter($pdf, '--browser-path'));
        $this->assertContains('--pdf', $pdf);
        $this->assertContains('--pptx', $pptx);

        $this->assertStringContainsString('/* @theme gitchronicle */', $this->toolInputs['theme']);
        $this->assertDoesNotMatchRegularExpression('/url\("fonts\/[a-z0-9-]+\.woff2"\)/', $this->toolInputs['theme']);
        $this->assertSame(10, substr_count($this->toolInputs['theme'], 'url("data:font/woff2;base64,'));
        $this->assertStringContainsString('![w:132](data:image/png;base64,', $this->toolInputs['deck']);
        $this->assertStringContainsString("## Containere\n\n![w:400](data:image/png;base64,", $this->toolInputs['deck']);

        [$diagram] = $this->commandsOf('diagram');
        $this->assertStringEndsWith('resources/js/lib/diagram-icons.json', $diagram[4]);
        $environment = $this->toolEnvironments[0];
        $this->assertSame('/usr/bin/google-chrome', $environment['CHROME_PATH']);
        $this->assertSame(Storage::disk('local')->path('hyperframes/home'), $environment['HOME']);
    }

    public function test_a_diagram_that_cannot_be_drawn_only_loses_its_slide(): void
    {
        $this->fakeMediaTools(failing: ['diagram']);
        $repository = Repository::factory()->ready()->create();

        RenderPresentationSlides::dispatch($repository, $this->facts(), $this->scenes(), 'abc123');

        $asset = MediaAsset::sole();
        $this->assertSame('ready', $asset->status->value);
        $this->assertSame(['magazin', 'What it does', 'In numbers', 'Closing'], $asset->meta['slides']);
    }

    public function test_a_failed_marp_marks_the_slides_failed(): void
    {
        $this->fakeMediaTools(failing: ['marp']);
        $repository = Repository::factory()->ready()->create();

        RenderPresentationSlides::dispatch($repository, $this->facts(), $this->scenes(), 'abc123');

        $this->assertDatabaseHas('media_assets', [
            'repository_id' => $repository->id,
            'kind' => 'slides',
            'status' => 'failed',
            'error' => RenderPresentationSlides::FAILED_MESSAGE,
        ]);
        Storage::disk('local')->assertMissing("media/{$repository->id}/work-slides");
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        return [
            'owner' => 'acme', 'name' => 'magazin', 'url' => 'https://github.com/acme/magazin', 'sha' => 'abc123',
            'description' => 'Un magazin mic.', 'github' => null, 'license' => null, 'languages' => [], 'modules' => [],
            'stats' => ['files' => 12, 'lines' => 3400, 'modules' => 3], 'history' => null, 'start_command' => null,
            'commands' => [], 'technologies' => [],
            'diagram' => ['type' => 'container', 'title' => 'Containere', 'caption' => null, 'mermaid' => "flowchart LR\n  a --> b\n"],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scenes(): array
    {
        return [
            ['key' => 'hook', 'title' => 'magazin', 'text' => 'Un magazin mic.', 'source' => null],
            ['key' => 'cta', 'text' => 'Vezi proiectul pe GitHub.', 'source' => null],
        ];
    }
}
