<?php

namespace Tests\Feature\Jobs;

use App\Ai\TextGenerator;
use App\Enums\ProcessingRunStatus;
use App\Jobs\FinishProcessing;
use App\Jobs\GenerateMedia;
use App\Jobs\RenderPresentationSlides;
use App\Jobs\RenderPresentationVideo;
use App\Models\MediaAsset;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAnalyzedRepositories;
use Tests\Concerns\FakesMediaTools;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The job that starts the video and the slides: when it runs, what it queues and when it does nothing.
 * GitHub is Http::fake, Claude is FakeTextGenerator and the tools are FakesMediaTools.
 */
class GenerateMediaTest extends TestCase
{
    use CreatesAnalyzedRepositories;
    use FakesMediaTools;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.github.com/*' => Http::response(['stargazers_count' => 12, 'language' => 'PHP'])]);
    }

    public function test_finishing_the_pipeline_queues_the_media_on_its_own_queue(): void
    {
        Queue::fake([GenerateMedia::class]);
        config(['gitchronicle.media.auto_generate' => true]);
        $repository = Repository::factory()->create();
        $run = ProcessingRun::factory()->for($repository)->create(['status' => ProcessingRunStatus::Running]);

        FinishProcessing::dispatch($repository, $run);

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
        Queue::assertPushedOn('media', GenerateMedia::class, fn (GenerateMedia $job): bool => $job->repository->is($repository) && ! $job->force);
    }

    public function test_the_media_can_be_turned_off(): void
    {
        Queue::fake([GenerateMedia::class]);
        config(['gitchronicle.media.auto_generate' => false]);
        $repository = Repository::factory()->create();

        FinishProcessing::dispatch($repository, ProcessingRun::factory()->for($repository)->create());

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
        Queue::assertNotPushed(GenerateMedia::class);
    }

    public function test_it_photographs_the_page_writes_the_script_once_and_queues_a_render_per_language(): void
    {
        $this->fakeMediaTools();
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator([json_encode([
            'hook' => ['en' => 'A small shop, as an example.', 'ro' => 'Un magazin mic, de exemplu.', 'quote' => 'Un magazin mic, folosit ca exemplu.'],
            'features' => [],
        ])]));
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatch($repository);

        $ai->assertSentCount(1);
        $this->assertCount(1, $this->commandsOf('capture'));
        $this->assertSame(
            ['slides-ro' => 'pending', 'video-en' => 'pending', 'video-ro' => 'pending'],
            MediaAsset::orderBy('kind')->orderBy('language')->get()->mapWithKeys(fn (MediaAsset $asset): array => ["{$asset->kind->value}-{$asset->language->value}" => $asset->status->value])->all(),
        );
        $this->assertSame([str_repeat('a', 40)], MediaAsset::distinct()->pluck('source_sha')->all());

        foreach (['en', 'ro'] as $language) {
            Queue::assertPushedOn('media', RenderPresentationVideo::class, fn (RenderPresentationVideo $job): bool => $job->repository->is($repository)
                && $job->language === $language
                && $job->sourceSha === str_repeat('a', 40)
                && $job->captureReason === null
                && strlen((string) $job->inputHash) === 64
                && $job->script['writer'] === 'ai'
                && $job->script['scenes'][0]['en']['narration'] === 'A small shop, as an example.'
                && $job->script['scenes'][0]['ro']['narration'] === 'Un magazin mic, de exemplu.');
        }

        // The slides are generated content: they get the English scenes.
        Queue::assertPushedOn('media', RenderPresentationSlides::class, fn (RenderPresentationSlides $job): bool => $job->facts['name'] === 'magazin'
            && $job->facts['github']['stars'] === 12
            && $job->scenes[0]['text'] === 'A small shop, as an example.'
            && ! array_key_exists('sources', $job->facts));
    }

    public function test_the_configured_languages_decide_which_videos_are_made(): void
    {
        config(['gitchronicle.media.languages' => ['ro']]);
        $this->fakeMediaTools();
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatch($repository);

        Queue::assertPushed(RenderPresentationVideo::class, 1);
        Queue::assertPushed(RenderPresentationVideo::class, fn (RenderPresentationVideo $job): bool => $job->language === 'ro');
    }

    public function test_when_github_cannot_be_photographed_the_renders_are_told_why(): void
    {
        $this->fakeMediaTools(failing: ['capture']);
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatch($repository);

        Queue::assertPushed(RenderPresentationVideo::class, fn (RenderPresentationVideo $job): bool => $job->captureReason === 'GitHub nu a putut fi contactat când s-a generat video-ul.');
    }

    public function test_nothing_is_made_again_for_the_same_commit_unless_forced(): void
    {
        $this->fakeMediaTools();
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        ['repository' => $repository] = $this->shopRepository();
        $this->readyMedia($repository, $repository->last_sha);

        GenerateMedia::dispatch($repository);

        Queue::assertNothingPushed();
        $this->assertCount(0, $this->commandsOf('capture'));
        $this->assertSame(['ready'], MediaAsset::distinct()->pluck('status')->map->value->all());

        GenerateMedia::dispatch($repository, force: true);

        Queue::assertPushed(RenderPresentationVideo::class, 2);
        Queue::assertPushed(RenderPresentationSlides::class, 1);
    }

    public function test_a_new_commit_that_changed_nothing_the_video_shows_only_marks_the_videos_current(): void
    {
        $this->fakeMediaTools();
        ['repository' => $repository] = $this->shopRepository();
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator([json_encode([
            'hook' => ['en' => 'A small shop, as an example.', 'ro' => 'Un magazin mic, de exemplu.', 'quote' => 'Un magazin mic, folosit ca exemplu.'],
        ])]));
        GenerateMedia::dispatchSync($repository, renderNow: true);
        $generatedAt = MediaAsset::where('kind', 'video')->pluck('generated_at', 'language')->all();
        $renders = count($this->commandsOf('hyperframes'));

        // A new commit that touched no source of the video (the same README, the same numbers as they are said).
        $repository->update(['last_sha' => str_repeat('b', 40)]);
        $this->travel(5)->minutes();
        GenerateMedia::dispatchSync($repository->fresh(), renderNow: true);

        $this->assertSame($renders, count($this->commandsOf('hyperframes')));
        $this->assertSame([str_repeat('b', 40)], MediaAsset::distinct()->pluck('source_sha')->all());
        $this->assertEquals($generatedAt, MediaAsset::where('kind', 'video')->pluck('generated_at', 'language')->all());
        $ai->assertSentCount(1);

        // A commit that changed the README: the videos are rendered again.
        file_put_contents($repository->fresh()->clonePath().'/README.md', "# Magazin\n\nUn magazin mic, folosit ca exemplu.\n\nAcum și cu facturi.\n");
        $repository->update(['last_sha' => str_repeat('c', 40)]);
        GenerateMedia::dispatchSync($repository->fresh(), renderNow: true);

        $this->assertSame($renders + 2, count($this->commandsOf('hyperframes')));
    }

    public function test_a_failed_asset_starts_only_what_is_missing_again(): void
    {
        $this->fakeMediaTools();
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        ['repository' => $repository] = $this->shopRepository();
        $this->readyMedia($repository, $repository->last_sha);
        MediaAsset::where('kind', 'slides')->update(['status' => 'failed']);

        GenerateMedia::dispatch($repository);

        Queue::assertPushed(RenderPresentationSlides::class, 1);
        Queue::assertNotPushed(RenderPresentationVideo::class);
    }

    public function test_without_the_tools_installed_everything_is_failed_with_the_reason_and_no_ai_call(): void
    {
        config(['gitchronicle.media.renderers_path' => Storage::disk('local')->path('no-renderers')]);
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator);
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatch($repository);

        $ai->assertSentCount(0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(3, MediaAsset::where('status', 'failed')->where('error', GenerateMedia::NOT_INSTALLED_MESSAGE)->count());
    }

    public function test_rendering_now_makes_the_videos_and_the_slides_without_a_worker(): void
    {
        $this->fakeMediaTools();
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatchSync($repository, renderNow: true);

        $this->assertSame(['ready'], MediaAsset::distinct()->pluck('status')->map->value->all());
        $video = MediaAsset::where('kind', 'video')->where('language', 'en')->sole();
        $this->assertSame(['template', null], [$video->meta['writer'], $video->meta['capture']['reason']]);
        $this->assertSame('en', MediaAsset::firstWhere('kind', 'slides')->language->value);
        Storage::disk('local')->assertExists(["media/{$repository->id}/video-en.mp4", "media/{$repository->id}/video-ro.mp4", "media/{$repository->id}/slides.pdf"]);
    }

    public function test_a_failed_video_does_not_stop_the_slides(): void
    {
        $this->fakeMediaTools(failing: ['hyperframes']);
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatchSync($repository, renderNow: true);

        $this->assertSame(['slides' => 'ready', 'video' => 'failed'], MediaAsset::orderBy('kind')->pluck('status', 'kind')->map->value->all());
    }

    public function test_a_generation_that_dies_does_not_leave_the_assets_pending(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->create(['kind' => 'video']);
        MediaAsset::factory()->for($repository)->readySlides()->create();

        (new GenerateMedia($repository))->failed(null);

        $this->assertSame(['slides' => 'ready', 'video' => 'failed'], MediaAsset::orderBy('kind')->pluck('status', 'kind')->map->value->all());
        $this->assertSame(MediaAsset::GENERIC_ERROR, MediaAsset::firstWhere('kind', 'video')->error);
    }

    public function test_deleting_the_repository_deletes_its_media(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readyVideo()->create();
        Storage::disk('local')->put("media/{$repository->id}/video.mp4", 'video');

        $repository->delete();

        Storage::disk('local')->assertMissing("media/{$repository->id}");
        $this->assertDatabaseCount('media_assets', 0);
    }

    /**
     * The videos of both languages and the slides, ready for a commit.
     */
    private function readyMedia(Repository $repository, ?string $sha): void
    {
        foreach (['en', 'ro'] as $language) {
            MediaAsset::factory()->for($repository)->readyVideo()->create(['language' => $language, 'source_sha' => $sha]);
        }

        MediaAsset::factory()->for($repository)->readySlides()->create(['source_sha' => $sha]);
    }
}
