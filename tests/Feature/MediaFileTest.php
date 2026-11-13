<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The files of the video (MediaFileController): bounded answers to "Range" requests, so that a playing video
 * never keeps the single PHP worker busy for long, and cache headers so that a repeat visit costs a 304.
 */
class MediaFileTest extends TestCase
{
    use RefreshDatabase;

    private Repository $repository;

    private string $content;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gitchronicle.media.max_range_bytes' => 100]);
        Storage::fake('local');

        $this->repository = Repository::factory()->ready()->create();
        $asset = MediaAsset::factory()->for($this->repository)->readyVideo()->create();
        $this->content = implode('', array_map(fn (int $number): string => chr(65 + $number % 26), range(0, 249)));

        foreach (['mp4', 'poster', 'vtt'] as $format) {
            Storage::disk('local')->put($asset->file($format), $format === 'mp4' ? $this->content : "content of {$format}");
        }
    }

    public function test_the_first_range_is_cut_to_the_configured_size(): void
    {
        $response = $this->get($this->url('video'), ['Range' => 'bytes=0-']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 0-99/250');
        $response->assertHeader('Content-Length', '100');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame(substr($this->content, 0, 100), $response->streamedContent());
    }

    public function test_a_middle_range_is_answered_from_its_first_byte(): void
    {
        $response = $this->get($this->url('video'), ['Range' => 'bytes=120-']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 120-219/250');
        $this->assertSame(substr($this->content, 120, 100), $response->streamedContent());
    }

    public function test_a_range_asked_smaller_than_the_limit_is_kept_as_asked(): void
    {
        $response = $this->get($this->url('video'), ['Range' => 'bytes=10-19']);

        $response->assertHeader('Content-Range', 'bytes 10-19/250');
        $this->assertSame(substr($this->content, 10, 10), $response->streamedContent());
    }

    public function test_the_last_range_ends_at_the_end_of_the_file(): void
    {
        $response = $this->get($this->url('video'), ['Range' => 'bytes=200-']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 200-249/250');
        $response->assertHeader('Content-Length', '50');
        $this->assertSame(substr($this->content, 200), $response->streamedContent());
    }

    public function test_a_suffix_range_gives_the_end_of_the_file(): void
    {
        $response = $this->get($this->url('video'), ['Range' => 'bytes=-20']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 230-249/250');
    }

    public function test_a_range_beyond_the_end_is_not_satisfiable(): void
    {
        $response = $this->get($this->url('video'), ['Range' => 'bytes=250-']);

        $response->assertStatus(416);
        $response->assertHeader('Content-Range', 'bytes */250');
    }

    public function test_a_request_without_a_range_gets_the_whole_file(): void
    {
        $response = $this->get($this->url('video'));

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame($this->content, $response->baseResponse->getFile()->getContent());
    }

    public function test_the_poster_has_cache_headers_and_is_answered_with_304_when_unchanged(): void
    {
        $first = $this->get($this->url('poster'));

        $first->assertOk();
        $this->assertStringContainsString('max-age=3600', (string) $first->headers->get('Cache-Control'));
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertNotNull($first->headers->get('Last-Modified'));

        $this->get($this->url('poster'), ['If-None-Match' => $etag])->assertStatus(304);
        $this->get($this->url('captions'))->assertOk()->assertHeader('ETag');
    }

    public function test_a_video_that_does_not_exist_is_a_404(): void
    {
        $other = Repository::factory()->ready()->create();

        $this->get("/repositories/{$other->id}/media/video", ['Range' => 'bytes=0-'])->assertNotFound();
    }

    private function url(string $file): string
    {
        return "/repositories/{$this->repository->id}/media/{$file}";
    }
}
