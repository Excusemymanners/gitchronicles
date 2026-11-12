<?php

namespace Tests\Feature\Console;

use App\Jobs\GenerateMedia;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAnalyzedRepositories;
use Tests\Concerns\FakesMediaTools;
use Tests\TestCase;

/**
 * `php artisan repositories:media`: the video and the slides of one repository, by hand.
 */
class GenerateRepositoryMediaTest extends TestCase
{
    use CreatesAnalyzedRepositories;
    use FakesMediaTools;
    use RefreshDatabase;

    public function test_it_queues_the_media_of_a_ready_repository(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);

        $this->artisan('repositories:media', ['repository' => $repository->id, '--force' => true])
            ->expectsOutputToContain("#{$repository->id} acme/magazin: queued the video and slides on the media queue")
            ->assertSuccessful();

        Queue::assertPushedOn('media', GenerateMedia::class, fn (GenerateMedia $job): bool => $job->repository->is($repository) && $job->force && ! $job->renderNow);
    }

    public function test_now_renders_in_the_terminal_and_reports_each_file(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        $this->fakeMediaTools();
        ['repository' => $repository] = $this->shopRepository();

        $this->artisan('repositories:media', ['repository' => $repository->id, '--now' => true])
            ->expectsOutputToContain("slides (en): Ready — storage/app/private/media/{$repository->id}/slides.pdf")
            ->expectsOutputToContain("video (en): Ready — storage/app/private/media/{$repository->id}/video-en.mp4")
            ->assertSuccessful();

        $this->assertSame(3, MediaAsset::where('status', 'ready')->count());
    }

    public function test_now_fails_when_a_render_fails(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        $this->fakeMediaTools(failing: ['marp']);
        ['repository' => $repository] = $this->shopRepository();

        $this->artisan('repositories:media', ['repository' => $repository->id, '--now' => true])
            ->expectsOutputToContain('slides (en): Failed')
            ->assertFailed();
    }

    public function test_it_refuses_a_missing_repository_and_one_that_is_not_ready(): void
    {
        Queue::fake();
        $pending = Repository::factory()->create();

        $this->artisan('repositories:media', ['repository' => 999])
            ->expectsOutputToContain('There is no repository with id 999.')
            ->assertFailed();
        $this->artisan('repositories:media', ['repository' => $pending->id])
            ->expectsOutputToContain('is not ready yet (status: Queued)')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
