<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The „Video” page: what it receives about the video and the slides, the routes that serve their files
 * from the private disk, and the cards and chapters of the workspace.
 */
class MediaPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_before_anything_was_generated_the_page_has_no_video_and_no_slides(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}/media");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/media')
            ->where('media', ['video' => null, 'slides' => null, 'is_working' => false])
            ->where('workspace.elements.video', null)
            ->where('workspace.elements.slides', null)
        );
    }

    public function test_a_ready_video_comes_with_its_files_scenes_and_sources(): void
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => str_repeat('a', 40)]);
        $this->travelTo('2026-11-10 12:00:00');
        $source = ['label' => 'README.md', 'quote' => 'Un magazin mic.', 'url' => 'https://github.com/acme/magazin/blob/aaa/README.md'];
        MediaAsset::factory()->for($repository)->readyVideo()->create([
            'source_sha' => str_repeat('a', 40),
            'meta' => ['scenes' => [
                ['key' => 'hook', 'label' => 'Ce este', 'title' => 'magazin', 'text' => 'Un magazin mic.', 'narration' => 'Un magazin mic.', 'source' => $source, 'start' => 0, 'duration' => 3.5],
                ['key' => 'proof', 'label' => 'Pe GitHub', 'note' => '12 fișiere', 'narration' => 'E scris în PHP.', 'source' => null, 'start' => 3.5, 'duration' => 3.2],
            ]],
        ]);
        $version = now()->timestamp;

        $response = $this->get("/repositories/{$repository->id}/media");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('media.is_working', false)
            ->where('media.slides', null)
            ->where('media.video', [
                'status' => 'ready',
                'status_label' => 'Gata',
                'error' => null,
                'generated_at' => '2026-11-10T12:00:00+00:00',
                'size_bytes' => 1048576,
                'is_stale' => false,
                'duration_ms' => 31200,
                'src' => "/repositories/{$repository->id}/media/video?v={$version}",
                'poster' => "/repositories/{$repository->id}/media/poster?v={$version}",
                'captions' => "/repositories/{$repository->id}/media/captions?v={$version}",
                'download' => "/repositories/{$repository->id}/media/download/mp4",
                'writer' => 'ai',
                'note' => null,
                'voice' => 'Piper · ro_RO-mihai-medium',
                'scenes' => [
                    ['key' => 'hook', 'label' => 'Ce este', 'start_ms' => 0, 'end_ms' => 3500, 'title' => 'magazin', 'text' => 'Un magazin mic.', 'command' => null, 'narration' => 'Un magazin mic.', 'source' => $source],
                    ['key' => 'proof', 'label' => 'Pe GitHub', 'start_ms' => 3500, 'end_ms' => 6700, 'title' => null, 'text' => '12 fișiere', 'command' => null, 'narration' => 'E scris în PHP.', 'source' => null],
                ],
            ])
        );
    }

    public function test_working_failed_and_stale_states_reach_the_page_without_file_addresses(): void
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => str_repeat('b', 40)]);
        MediaAsset::factory()->for($repository)->create(['kind' => 'video', 'status' => 'running', 'source_sha' => str_repeat('b', 40)]);
        MediaAsset::factory()->for($repository)->readySlides()->create(['source_sha' => str_repeat('a', 40)]);

        $response = $this->get("/repositories/{$repository->id}/media");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('media.is_working', true)
            ->where('media.video.status', 'running')
            ->where('media.video.status_label', 'Se generează')
            ->where('media.video.src', null)
            ->where('media.video.download', null)
            ->where('media.slides.status', 'ready')
            ->where('media.slides.is_stale', true)
            ->where('media.slides.titles', ['magazin', 'Ce face'])
            ->where('media.slides.downloads', [
                'pdf' => "/repositories/{$repository->id}/media/download/pdf",
                'pptx' => "/repositories/{$repository->id}/media/download/pptx",
            ])
        );

        MediaAsset::query()->update(['status' => 'failed', 'error' => 'Randarea video-ului a eșuat.']);

        $this->get("/repositories/{$repository->id}/media")->assertInertia(fn (Assert $page) => $page
            ->where('media.is_working', false)
            ->where('media.video.error', 'Randarea video-ului a eșuat.')
            ->where('media.slides.downloads', ['pdf' => null, 'pptx' => null])
        );
    }

    public function test_the_poll_asks_only_for_the_media(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->create();

        $response = $this->get("/repositories/{$repository->id}/media");

        $response->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('media', fn (Assert $reload) => $reload
                ->where('media.video.status', 'pending')
                ->missing('workspace')
                ->missing('repository')
            )
        );
    }

    public function test_the_video_is_served_whole_and_in_ranges(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readyVideo()->create();
        Storage::disk('local')->put("media/{$repository->id}/video.mp4", '0123456789');

        $whole = $this->get("/repositories/{$repository->id}/media/video");
        $range = $this->get("/repositories/{$repository->id}/media/video", ['Range' => 'bytes=2-5']);

        $whole->assertOk();
        $whole->assertHeader('Content-Type', 'video/mp4');
        $whole->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringContainsString('max-age=3600', $whole->headers->get('Cache-Control'));
        $this->assertSame('0123456789', $whole->streamedContent());
        $range->assertStatus(206);
        $range->assertHeader('Content-Range', 'bytes 2-5/10');
        $this->assertSame('2345', $range->streamedContent());
    }

    public function test_the_poster_and_the_captions_are_served_with_their_types(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readyVideo()->create();
        Storage::disk('local')->put("media/{$repository->id}/poster.jpg", 'jpeg');
        Storage::disk('local')->put("media/{$repository->id}/captions.vtt", "WEBVTT\n");

        $this->get("/repositories/{$repository->id}/media/poster")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $captions = $this->get("/repositories/{$repository->id}/media/captions");

        $captions->assertOk();
        $this->assertStringStartsWith('text/vtt', $captions->headers->get('Content-Type'));
        $this->assertSame("WEBVTT\n", $captions->streamedContent());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function downloads(): array
    {
        return [
            'video' => ['mp4', 'video.mp4', 'acme-magazin-video.mp4'],
            'pdf' => ['pdf', 'slides.pdf', 'acme-magazin-prezentare.pdf'],
            'pptx' => ['pptx', 'slides.pptx', 'acme-magazin-prezentare.pptx'],
        ];
    }

    #[DataProvider('downloads')]
    public function test_a_guest_downloads_each_file_named_after_the_repository(string $format, string $file, string $downloadName): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);
        MediaAsset::factory()->for($repository)->readyVideo()->create();
        MediaAsset::factory()->for($repository)->readySlides()->create();
        Storage::disk('local')->put("media/{$repository->id}/{$file}", 'content');

        $response = $this->get("/repositories/{$repository->id}/media/download/{$format}");

        $response->assertOk();
        $response->assertDownload($downloadName);
    }

    public function test_files_that_are_not_ready_missing_or_of_another_format_return_404(): void
    {
        $repository = Repository::factory()->ready()->create();
        $other = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->create(['kind' => 'video', 'status' => 'running', 'path' => "media/{$repository->id}/video.mp4"]);
        MediaAsset::factory()->for($repository)->readySlides()->create();
        Storage::disk('local')->put("media/{$repository->id}/video.mp4", 'an older video');
        Storage::disk('local')->put('repositories/secret.txt', 'secret');

        $this->get("/repositories/{$repository->id}/media/video")->assertNotFound();
        $this->get("/repositories/{$repository->id}/media/download/mp4")->assertNotFound();
        $this->get("/repositories/{$repository->id}/media/download/pdf")->assertNotFound();
        $this->get("/repositories/{$repository->id}/media/download/zip")->assertNotFound();
        $this->get("/repositories/{$repository->id}/media/download/..%2F..%2Frepositories%2Fsecret.txt")->assertNotFound();
        $this->get("/repositories/{$other->id}/media/poster")->assertNotFound();
        $this->get('/repositories/999/media/video')->assertNotFound();
    }

    public function test_the_workspace_gets_the_video_and_slides_cards_and_chapters(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->travelTo('2026-11-10 12:00:00');
        MediaAsset::factory()->for($repository)->readyVideo()->create();
        MediaAsset::factory()->for($repository)->create(['kind' => 'slides', 'status' => 'running']);
        $version = now()->timestamp;

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('workspace.elements.video', [
                'href' => "/repositories/{$repository->id}/media",
                'status' => 'ready',
                'status_label' => 'Gata',
                'poster' => "/repositories/{$repository->id}/media/poster?v={$version}",
                'duration_ms' => 31200,
            ])
            ->where('workspace.elements.slides', [
                'href' => "/repositories/{$repository->id}/media#slide-uri",
                'status' => 'running',
                'status_label' => 'Se generează',
                'slide_count' => null,
            ])
            ->where('workspace.chapters.1', [
                'key' => 'media',
                'title' => 'Prezentare',
                'count' => null,
                'chapters' => [
                    ['label' => 'Video', 'href' => "/repositories/{$repository->id}/media", 'count' => null],
                    ['label' => 'Slide-uri', 'href' => "/repositories/{$repository->id}/media#slide-uri", 'count' => null],
                ],
                'folders' => [],
            ])
        );
    }

    public function test_ready_slides_show_how_many_they_are(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readySlides()->create();

        $this->get("/repositories/{$repository->id}")->assertInertia(fn (Assert $page) => $page
            ->where('workspace.elements.slides.slide_count', 2)
            ->where('workspace.elements.video', null)
        );
    }
}
