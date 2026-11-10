<?php

namespace Tests\Feature\Jobs;

use App\Enums\MediaKind;
use App\Jobs\RenderPresentationVideo;
use App\Media\AudioMixer;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMediaTools;
use Tests\TestCase;

/**
 * The video job and its renderer, with every tool faked (FakesMediaTools): which commands run, with
 * what data and environment, what is saved in media_assets and what a failure leaves behind.
 */
class RenderPresentationVideoTest extends TestCase
{
    use FakesMediaTools;
    use RefreshDatabase;

    public function test_it_renders_the_video_with_the_voice_and_saves_the_result(): void
    {
        $this->fakeMediaTools(clipSeconds: 2.5);
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);
        $this->travelTo('2026-11-10 12:00:00');

        RenderPresentationVideo::dispatch($repository, $this->script(), 'abc123');

        $asset = MediaAsset::sole();
        $directory = "media/{$repository->id}";
        $this->assertSame(MediaKind::Video, $asset->kind);
        $this->assertDatabaseHas('media_assets', [
            'repository_id' => $repository->id,
            'kind' => 'video',
            'status' => 'ready',
            'path' => "{$directory}/video.mp4",
            'source_sha' => 'abc123',
            'duration_ms' => 11267,
            'size_bytes' => 5,
            'error' => null,
            'generated_at' => '2026-11-10 12:00:00',
        ]);
        Storage::disk('local')->assertExists(["{$directory}/video.mp4", "{$directory}/poster.jpg", "{$directory}/captions.vtt"]);
        Storage::disk('local')->assertMissing("{$directory}/work-video");
        $this->assertSame(['mp4' => "{$directory}/video.mp4", 'poster' => "{$directory}/poster.jpg", 'vtt' => "{$directory}/captions.vtt"], $asset->meta['files']);
        $this->assertSame(['ai', 'claude-opus-5-5', 'Piper · ro'], [$asset->meta['writer'], $asset->meta['model'], $asset->meta['voice']]);
        $this->assertEquals([0, 3.267, 7.467], array_column($asset->meta['scenes'], 'start'));
        $this->assertSame([3.267, 4.2, 3.8], array_column($asset->meta['scenes'], 'duration'));
        $this->assertSame('Un magazin mic.', $asset->meta['scenes'][0]['source']['quote']);
        $this->assertArrayNotHasKey('voice_file', $asset->meta['scenes'][0]);
        $this->assertStringContainsString("00:00:00.300 --> 00:00:02.800\nMagazinul care ține minte coșul.", Storage::disk('local')->get("{$directory}/captions.vtt"));

        $this->assertSame(
            ['piper', 'ffprobe', 'piper', 'ffprobe', 'piper', 'ffprobe', 'hyperframes', 'music', 'ffmpeg', 'ffmpeg'],
            array_map($this->toolOf(...), $this->toolCommands),
        );
    }

    public function test_hyperframes_gets_the_scenes_the_total_length_and_an_isolated_environment(): void
    {
        $this->fakeMediaTools();
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);

        RenderPresentationVideo::dispatch($repository, $this->script(), 'abc123');

        $data = json_decode($this->toolInputs['data'], true);
        $this->assertSame(['owner' => 'acme', 'name' => 'magazin'], $data['repo']);
        $this->assertSame(['hook', 'start', 'cta'], array_column($data['scenes'], 'key'));
        $this->assertSame('composer install', $data['scenes'][1]['command']);
        $this->assertArrayNotHasKey('narration', $data['scenes'][0]);
        $this->assertArrayNotHasKey('source', $data['scenes'][0]);
        $this->assertStringContainsString('<div id="root" data-composition-id="root" data-start="0" data-duration="11.267"', $this->toolInputs['composition']);

        [$hyperframes] = $this->commandsOf('hyperframes');
        $this->assertSame(['--format', 'mp4', '--fps', '30', '--quality', 'looks', '--workers', '4', '--quiet', '--json'], array_slice($hyperframes, -10));

        $environment = $this->toolEnvironments[array_search($hyperframes, $this->toolCommands, true)];
        $home = Storage::disk('local')->path('hyperframes');
        $this->assertSame("{$home}/home", $environment['HOME']);
        $this->assertSame("{$home}/cache", $environment['HYPERFRAMES_CACHE_DIR']);
        $this->assertSame("{$home}/config", $environment['HYPERFRAMES_CONFIG_DIR']);
        $this->assertSame(['1', '1', '1', '1'], [$environment['HYPERFRAMES_NO_TELEMETRY'], $environment['DO_NOT_TRACK'], $environment['HYPERFRAMES_NO_UPDATE_CHECK'], $environment['HYPERFRAMES_NO_AUTO_INSTALL']]);
        $this->assertSame('/usr/bin/google-chrome', $environment['HYPERFRAMES_BROWSER_PATH']);
        $this->assertSame('/usr/bin/ffmpeg', $environment['HYPERFRAMES_FFMPEG_PATH']);
    }

    public function test_the_voice_is_mixed_over_the_ducked_music_at_the_time_of_each_scene(): void
    {
        $this->fakeMediaTools();
        $repository = Repository::factory()->ready()->create();

        RenderPresentationVideo::dispatch($repository, $this->script(), 'abc123');

        [$piper] = $this->commandsOf('piper');
        $this->assertSame(['-m', 'piper', '--model', Storage::disk('local')->path('voice/ro.onnx')], array_slice($piper, 1, 4));
        $this->assertNotContains('Magazinul care ține minte coșul.', $piper);

        [$music] = $this->commandsOf('music');
        $this->assertSame('11.267', $music[2]);

        [$mix, $poster] = $this->commandsOf('ffmpeg');
        $filters = $this->argumentAfter($mix, '-filter_complex');
        $this->assertStringContainsString('[1:a]aresample=48000,aformat=channel_layouts=stereo,adelay=300:all=1[v0]', $filters);
        $this->assertStringContainsString('adelay=3567:all=1[v1]', $filters);
        $this->assertStringContainsString('adelay=7767:all=1[v2]', $filters);
        $this->assertStringContainsString('[4:a]aresample=48000,volume='.AudioMixer::MUSIC_VOLUME.'[music]', $filters);
        $this->assertStringContainsString('[music][voicekey]sidechaincompress=', $filters);
        $this->assertSame(['-c:v', 'copy', '-c:a', 'aac'], array_slice($mix, array_search('-c:v', $mix, true), 4));
        $this->assertSame('11.267', $this->argumentAfter($mix, '-t'));
        $this->assertSame('2.400', $this->argumentAfter($poster, '-ss'));
    }

    public function test_without_piper_the_video_has_music_and_captions_only(): void
    {
        $this->fakeMediaTools(withVoice: false);
        $repository = Repository::factory()->ready()->create();

        RenderPresentationVideo::dispatch($repository, $this->script(), 'abc123');

        $asset = MediaAsset::sole();
        $this->assertSame('ready', $asset->status->value);
        $this->assertNull($asset->meta['voice']);
        $this->assertNull($asset->meta['scenes'][0]['voice_at']);
        $this->assertSame([], $this->commandsOf('piper'));
        [$mix] = $this->commandsOf('ffmpeg');
        $this->assertStringStartsWith('[1:a]aresample=48000,volume=', $this->argumentAfter($mix, '-filter_complex'));
        $this->assertStringContainsString("00:00:00.000 --> 00:00:03.400\nMagazinul care ține minte coșul.", Storage::disk('local')->get("media/{$repository->id}/captions.vtt"));
    }

    public function test_a_failed_render_marks_the_video_failed_and_keeps_the_previous_files(): void
    {
        $this->fakeMediaTools(failing: ['hyperframes']);
        $repository = Repository::factory()->ready()->create();
        Storage::disk('local')->put("media/{$repository->id}/video.mp4", 'the previous video');

        RenderPresentationVideo::dispatch($repository, $this->script(), 'abc123');

        $this->assertDatabaseHas('media_assets', [
            'repository_id' => $repository->id,
            'kind' => 'video',
            'status' => 'failed',
            'error' => RenderPresentationVideo::FAILED_MESSAGE,
        ]);
        $this->assertSame('the previous video', Storage::disk('local')->get("media/{$repository->id}/video.mp4"));
        Storage::disk('local')->assertMissing("media/{$repository->id}/work-video");
    }

    public function test_a_job_stopped_by_the_worker_does_not_leave_the_video_running(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->create(['status' => 'running']);

        (new RenderPresentationVideo($repository, $this->script(), 'abc123'))->failed(null);

        $this->assertDatabaseHas('media_assets', ['repository_id' => $repository->id, 'status' => 'failed', 'error' => RenderPresentationVideo::FAILED_MESSAGE]);
    }

    public function test_the_job_runs_on_the_media_queue(): void
    {
        $job = new RenderPresentationVideo(Repository::factory()->ready()->create(), $this->script(), 'abc123');

        $this->assertSame('media', $job->queue);
    }

    /**
     * A script with three scenes, as PresentationScript::toArray() gives it.
     *
     * @return array<string, mixed>
     */
    private function script(): array
    {
        $source = ['label' => 'README.md', 'quote' => 'Un magazin mic.', 'url' => null];

        return [
            'scenes' => [
                ['key' => 'hook', 'label' => 'Ce este', 'kicker' => 'acme /', 'title' => 'magazin', 'text' => 'Magazinul care ține minte coșul.', 'accent' => null, 'narration' => 'Magazinul care ține minte coșul.', 'source' => $source],
                ['key' => 'start', 'label' => 'Cum începi', 'text' => 'Pornești cu o comandă.', 'accent' => null, 'command' => 'composer install', 'narration' => 'Pornești cu o comandă.', 'source' => $source],
                ['key' => 'cta', 'label' => 'Unde îl găsești', 'text' => 'Vezi proiectul pe GitHub.', 'accent' => null, 'url' => 'github.com/acme/magazin', 'narration' => 'Îl găsești pe GitHub.', 'source' => $source],
            ],
            'writer' => 'ai',
            'model' => 'claude-opus-5-5',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'rejected' => [],
            'note' => null,
        ];
    }
}
