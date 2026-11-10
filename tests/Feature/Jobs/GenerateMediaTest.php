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

    public function test_it_writes_the_script_and_queues_the_two_renders(): void
    {
        $this->fakeMediaTools();
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator([json_encode([
            'hook' => ['text' => 'Un magazin mic, de exemplu.', 'source' => 'Un magazin mic, folosit ca exemplu.'],
            'features' => [],
            'cta' => null,
        ])]));
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatch($repository);

        $ai->assertSentCount(1);
        $this->assertSame(['slides' => 'pending', 'video' => 'pending'], MediaAsset::orderBy('kind')->pluck('status', 'kind')->map->value->all());
        $this->assertSame([str_repeat('a', 40)], MediaAsset::distinct()->pluck('source_sha')->all());
        Queue::assertPushedOn('media', RenderPresentationVideo::class, fn (RenderPresentationVideo $job): bool => $job->repository->is($repository)
            && $job->sourceSha === str_repeat('a', 40)
            && $job->script['writer'] === 'ai'
            && $job->script['scenes'][0]['text'] === 'Un magazin mic, de exemplu.');
        Queue::assertPushedOn('media', RenderPresentationSlides::class, fn (RenderPresentationSlides $job): bool => $job->facts['name'] === 'magazin'
            && $job->facts['github']['stars'] === 12
            && $job->scenes[0]['text'] === 'Un magazin mic, de exemplu.'
            && ! array_key_exists('sources', $job->facts));
    }

    public function test_nothing_is_made_again_for_the_same_commit_unless_forced(): void
    {
        $this->fakeMediaTools();
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        ['repository' => $repository] = $this->shopRepository();
        MediaAsset::factory()->for($repository)->readyVideo()->create(['source_sha' => $repository->last_sha]);
        MediaAsset::factory()->for($repository)->readySlides()->create(['source_sha' => $repository->last_sha]);

        GenerateMedia::dispatch($repository);

        Queue::assertNothingPushed();
        $this->assertSame(['ready'], MediaAsset::distinct()->pluck('status')->map->value->all());

        GenerateMedia::dispatch($repository, force: true);

        Queue::assertPushed(RenderPresentationVideo::class, 1);
        Queue::assertPushed(RenderPresentationSlides::class, 1);
    }

    public function test_a_new_commit_or_a_failed_asset_starts_the_media_again(): void
    {
        $this->fakeMediaTools();
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        ['repository' => $repository] = $this->shopRepository();
        MediaAsset::factory()->for($repository)->readyVideo()->create(['source_sha' => str_repeat('b', 40)]);
        MediaAsset::factory()->for($repository)->readySlides()->create(['source_sha' => str_repeat('b', 40)]);

        GenerateMedia::dispatch($repository);

        Queue::assertPushed(RenderPresentationVideo::class, 1);

        MediaAsset::query()->update(['source_sha' => $repository->last_sha, 'status' => 'ready']);
        MediaAsset::where('kind', 'slides')->update(['status' => 'failed']);

        GenerateMedia::dispatch($repository);

        Queue::assertPushed(RenderPresentationVideo::class, 2);
    }

    public function test_without_the_tools_installed_both_are_failed_with_the_reason_and_no_ai_call(): void
    {
        config(['gitchronicle.media.renderers_path' => Storage::disk('local')->path('no-renderers')]);
        Queue::fake([RenderPresentationVideo::class, RenderPresentationSlides::class]);
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator);
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatch($repository);

        $ai->assertSentCount(0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(2, MediaAsset::where('status', 'failed')->where('error', GenerateMedia::NOT_INSTALLED_MESSAGE)->count());
    }

    public function test_rendering_now_makes_the_video_and_the_slides_without_a_worker(): void
    {
        $this->fakeMediaTools();
        ['repository' => $repository] = $this->shopRepository();

        GenerateMedia::dispatchSync($repository, renderNow: true);

        $this->assertSame(['slides' => 'ready', 'video' => 'ready'], MediaAsset::orderBy('kind')->pluck('status', 'kind')->map->value->all());
        $this->assertSame('template', MediaAsset::firstWhere('kind', 'video')->meta['writer']);
        Storage::disk('local')->assertExists(["media/{$repository->id}/video.mp4", "media/{$repository->id}/slides.pdf"]);
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
}
