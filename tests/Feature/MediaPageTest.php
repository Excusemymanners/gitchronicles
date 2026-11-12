<?php

namespace Tests\Feature;

use App\Jobs\GenerateMedia;
use App\Models\MediaAsset;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            ->where('media', ['videos' => [], 'default_language' => 'en', 'slides' => null, 'is_working' => false, 'can_generate' => false])
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
            ->where('media.videos.0', [
                'status' => 'ready',
                'status_label' => 'Ready',
                'error' => null,
                'generated_at' => '2026-11-10T12:00:00+00:00',
                'size_bytes' => 1048576,
                'is_stale' => false,
                'duration_ms' => 31200,
                'language' => 'ro',
                'language_label' => 'Română',
                'capture_note' => null,
                'src' => "/repositories/{$repository->id}/media/video?lang=ro&v={$version}",
                'poster' => "/repositories/{$repository->id}/media/poster?lang=ro&v={$version}",
                'captions' => "/repositories/{$repository->id}/media/captions?lang=ro&v={$version}",
                'download' => "/repositories/{$repository->id}/media/download/mp4?lang=ro",
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
            ->where('media.videos.0.status', 'running')
            ->where('media.videos.0.status_label', 'Generating')
            ->where('media.videos.0.src', null)
            ->where('media.videos.0.download', null)
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
            ->where('media.videos.0.error', 'Randarea video-ului a eșuat.')
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
                ->where('media.videos.0.status', 'pending')
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
            'video' => ['mp4', 'video.mp4', 'acme-magazin-ro-video.mp4'],
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
                'status_label' => 'Ready',
                'poster' => "/repositories/{$repository->id}/media/poster?lang=ro&v={$version}",
                'duration_ms' => 31200,
            ])
            ->where('workspace.elements.slides', [
                'href' => "/repositories/{$repository->id}/media#slide-uri",
                'status' => 'running',
                'status_label' => 'Generating',
                'slide_count' => null,
            ])
            ->where('workspace.chapters.1', [
                'key' => 'media',
                'title' => 'Presentation',
                'count' => null,
                'chapters' => [
                    ['label' => 'Video', 'href' => "/repositories/{$repository->id}/media", 'count' => null],
                    ['label' => 'Slides', 'href' => "/repositories/{$repository->id}/media#slide-uri", 'count' => null],
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

    public function test_the_page_gets_one_video_per_language_with_the_default_first(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readyVideo()->create(['language' => 'ro']);
        MediaAsset::factory()->for($repository)->readyVideo()->create([
            'language' => 'en',
            'meta' => ['files' => ['mp4' => "media/{$repository->id}/video-en.mp4"], 'capture' => ['reason' => 'GitHub nu a putut fi contactat când s-a generat video-ul.']],
        ]);
        Storage::disk('local')->put("media/{$repository->id}/video-en.mp4", 'english');
        Storage::disk('local')->put("media/{$repository->id}/video.mp4", 'romana');

        $response = $this->get("/repositories/{$repository->id}/media");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('media.default_language', 'en')
            ->where('media.videos.0.language', 'en')
            ->where('media.videos.0.language_label', 'English')
            ->where('media.videos.0.capture_note', 'GitHub nu a putut fi contactat când s-a generat video-ul.')
            ->where('media.videos.1.language', 'ro')
            ->where('media.videos.1.language_label', 'Română')
            ->where('workspace.elements.video.status', 'ready')
        );

        // Each language has its own file; without "lang" the default language is served.
        $this->assertSame('english', $this->get("/repositories/{$repository->id}/media/video?lang=en")->streamedContent());
        $this->assertSame('romana', $this->get("/repositories/{$repository->id}/media/video?lang=ro")->streamedContent());
        $this->assertSame('english', $this->get("/repositories/{$repository->id}/media/video")->streamedContent());
        $this->get("/repositories/{$repository->id}/media/download/mp4?lang=en")->assertDownload("{$repository->owner}-{$repository->name}-en-video.mp4");
        $this->get("/repositories/{$repository->id}/media/video?lang=fr")->assertNotFound();
    }

    public function test_a_logged_in_user_starts_the_generation_and_the_page_shows_it_waiting(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readyVideo()->create(['language' => 'ro', 'status' => 'failed', 'error' => 'Randarea video-ului a eșuat.']);
        $user = User::factory()->create();

        $this->get("/repositories/{$repository->id}/media")->assertInertia(fn (Assert $page) => $page->where('media.can_generate', false));
        $this->actingAs($user)->get("/repositories/{$repository->id}/media")->assertInertia(fn (Assert $page) => $page->where('media.can_generate', true));

        $response = $this->actingAs($user)->post("/repositories/{$repository->id}/media/generate");

        $response->assertRedirect("/repositories/{$repository->id}/media");
        Queue::assertPushedOn('media', GenerateMedia::class, fn (GenerateMedia $job): bool => $job->repository->is($repository) && $job->force && ! $job->rewrite);
        // English and Romanian videos and the slides wait at once; the old error is gone.
        $this->assertSame(3, MediaAsset::where('status', 'pending')->whereNull('error')->count());
        $this->actingAs($user)->get("/repositories/{$repository->id}/media")->assertInertia(fn (Assert $page) => $page
            ->where('media.is_working', true)
            ->where('media.can_generate', false)
        );

        // While something is waiting or rendering, a second start is refused — also for an administrator.
        $this->actingAs($user)->post("/repositories/{$repository->id}/media/generate")->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post("/repositories/{$repository->id}/media/generate")->assertForbidden();
        Queue::assertPushed(GenerateMedia::class, 1);
    }

    public function test_guests_and_repositories_that_are_not_ready_cannot_start_the_generation(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();
        $processing = Repository::factory()->create(['status' => 'analyzing']);

        $this->post("/repositories/{$repository->id}/media/generate")->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post("/repositories/{$processing->id}/media/generate")->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('media_assets', 0);
    }
}
