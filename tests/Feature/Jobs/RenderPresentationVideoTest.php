<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RenderPresentationVideo;
use App\Media\AudioMixer;
use App\Media\GitHubCapture;
use App\Models\MediaAsset;
use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMediaTools;
use Tests\TestCase;

/**
 * The video job and its renderer, with every tool faked (FakesMediaTools): which commands run, with
 * what data and environment, what is saved in media_assets per language and what a failure leaves behind.
 */
class RenderPresentationVideoTest extends TestCase
{
    use FakesMediaTools;
    use RefreshDatabase;

    public function test_it_renders_one_language_on_the_photographed_page_and_saves_the_result(): void
    {
        $this->fakeMediaTools(clipSeconds: 2.5);
        $repository = $this->repositoryWithCapture();
        $this->travelTo('2026-11-10 12:00:00');

        RenderPresentationVideo::dispatch($repository, $this->script(), 'en', 'abc123', 'hash-1');

        $asset = MediaAsset::sole();
        $directory = "media/{$repository->id}";
        $this->assertDatabaseHas('media_assets', [
            'repository_id' => $repository->id, 'kind' => 'video', 'language' => 'en', 'status' => 'ready',
            'path' => "{$directory}/video-en.mp4", 'source_sha' => 'abc123', 'size_bytes' => 5, 'error' => null, 'generated_at' => '2026-11-10 12:00:00',
        ]);
        Storage::disk('local')->assertExists(["{$directory}/video-en.mp4", "{$directory}/poster-en.jpg", "{$directory}/captions-en.vtt"]);
        Storage::disk('local')->assertMissing("{$directory}/work-video-en");
        $this->assertSame(['ai', 'claude-opus-5-5', 'Piper · en', 'hash-1'], [$asset->meta['writer'], $asset->meta['model'], $asset->meta['voice'], $asset->meta['input_hash']]);
        $this->assertSame(['context_hash' => 'ctx', 'answer' => ['hook' => []]], array_intersect_key($asset->meta['script'], ['context_hash' => 1, 'answer' => 1]));
        $this->assertSame(['captured_at' => '2026-11-12T06:24:56.547Z', 'reason' => null], $asset->meta['capture']);

        // Hook: one sentence (0.35 + 2.5 + 0.45 = 3.3). Start: two sentences. The scenes keep their sources, not the camera data.
        $this->assertEquals([0, 3.3, 9.233], array_column($asset->meta['scenes'], 'start'));
        $this->assertSame(['What it is', 'The shop that remembers your cart.'], [$asset->meta['scenes'][0]['label'], $asset->meta['scenes'][0]['narration']]);
        $this->assertSame('Un magazin mic.', $asset->meta['scenes'][0]['source']['quote']);
        $this->assertArrayNotHasKey('shots', $asset->meta['scenes'][0]);
        $this->assertArrayNotHasKey('ro', $asset->meta['scenes'][0]);
        $this->assertStringContainsString('00:00:00.350 --> ', Storage::disk('local')->get("{$directory}/captions-en.vtt"));
        $this->assertStringContainsString('The shop that', Storage::disk('local')->get("{$directory}/captions-en.vtt"));

        // One Piper clip per sentence (4), then the picture, the music, the loudness of the voice, the mix and the poster.
        $this->assertSame(
            ['piper', 'piper', 'piper', 'piper', 'ffprobe', 'ffprobe', 'ffprobe', 'ffprobe', 'hyperframes', 'music', 'loudness', 'ffmpeg', 'ffmpeg'],
            array_map($this->toolOf(...), $this->toolCommands),
        );
    }

    public function test_hyperframes_gets_the_page_the_shots_the_captions_and_an_isolated_environment(): void
    {
        $this->fakeMediaTools();
        $repository = $this->repositoryWithCapture();

        RenderPresentationVideo::dispatch($repository, $this->script(), 'ro', 'abc123');

        $data = json_decode($this->toolInputs['data'], true);
        $this->assertSame(['ro', ['owner' => 'excusemymanners', 'name' => 'gitchronicles']], [$data['language'], $data['repo']]);
        $this->assertSame([400, 2.7, 'capture/page-00.png', 'capture/avatar.png'], [$data['capture']['width'], $data['capture']['scale'], $data['capture']['tiles'][0]['file'], $data['capture']['avatar']]);
        $this->assertSame(['hook', 'start', 'cta'], array_column($data['scenes'], 'key'));
        $this->assertSame('Ce este', $data['scenes'][0]['label']);
        // The camera: the real name of the repository, then the exact line of the command.
        $this->assertEquals(['name', 168, 0], [$data['scenes'][0]['shots'][0]['kind'], $data['scenes'][0]['shots'][0]['y'], $data['scenes'][0]['shots'][0]['time']]);
        $this->assertSame(['command', 8467], [$data['scenes'][1]['shots'][0]['kind'], $data['scenes'][1]['shots'][0]['y']]);
        $this->assertSame('Magazinul', $data['scenes'][0]['cues'][0]['words'][0]['text']);
        $this->assertArrayNotHasKey('narration', $data['scenes'][0]);
        $this->assertArrayNotHasKey('source', $data['scenes'][0]);
        $this->assertStringContainsString('data-duration="13.033"', $this->toolInputs['composition']);

        [$hyperframes] = $this->commandsOf('hyperframes');
        // HyperFrames writes an almost lossless master: the final picture is encoded once, by the mix.
        $this->assertSame(['--format', 'mp4', '--fps', '30', '--quality', 'looks', '--crf', '10', '--workers', '4', '--quiet', '--json'], array_slice($hyperframes, -12));

        $environment = $this->toolEnvironments[array_search($hyperframes, $this->toolCommands, true)];
        $home = Storage::disk('local')->path('hyperframes');
        $this->assertSame(["{$home}/home", "{$home}/cache", '1', '/usr/bin/google-chrome'], [$environment['HOME'], $environment['HYPERFRAMES_CACHE_DIR'], $environment['HYPERFRAMES_NO_TELEMETRY'], $environment['HYPERFRAMES_BROWSER_PATH']]);
    }

    public function test_each_sentence_is_spoken_by_the_voice_of_the_language_and_mixed_at_its_moment(): void
    {
        $this->fakeMediaTools();
        $repository = $this->repositoryWithCapture();

        RenderPresentationVideo::dispatch($repository, $this->script(), 'ro', 'abc123');

        [$piper] = $this->commandsOf('piper');
        $this->assertSame(['-m', 'piper', '--model', Storage::disk('local')->path('voice/ro.onnx')], array_slice($piper, 1, 4));
        $this->assertNotContains('Magazinul care ține minte coșul.', $piper);

        [$mix] = $this->commandsOf('ffmpeg');
        $filters = $this->argumentAfter($mix, '-filter_complex');
        $this->assertStringContainsString('[1:a]aresample=48000,aformat=channel_layouts=stereo,adelay=350:all=1[v0]', $filters);
        // The second sentence of the „Cum începi” scene starts after the first one and a short breath.
        $this->assertStringContainsString('adelay=3650:all=1[v1]', $filters);
        $this->assertStringContainsString('adelay=6290:all=1[v2]', $filters);
        // The fake voice measures -20 LUFS: it is raised by 3 dB to the common level.
        $this->assertStringContainsString('amix=inputs=4:normalize=0:duration=longest,volume=3.00dB,apad[voice]', $filters);
        $this->assertStringContainsString('[5:a]aresample=48000,volume='.AudioMixer::MUSIC_VOLUME.'[music]', $filters);
        $this->assertSame(['-c:v', 'libx264', '-preset', 'slow', '-crf', '20'], array_slice($mix, array_search('-c:v', $mix, true), 6));
        $this->assertSame(['-c:a', 'aac'], array_slice($mix, array_search('-c:a', $mix, true), 2));
    }

    public function test_without_the_photo_the_video_has_typographic_scenes_and_says_why(): void
    {
        $this->fakeMediaTools(withVoice: false);
        $repository = Repository::factory()->ready()->create();

        RenderPresentationVideo::dispatch($repository, $this->script(), 'en', 'abc123', null, StoredMessage::make('media.capture.unreachable'));

        $asset = MediaAsset::sole();
        $data = json_decode($this->toolInputs['data'], true);
        $this->assertSame('ready', $asset->status->value);
        $this->assertNull($data['capture']);
        $this->assertSame([], $data['scenes'][0]['shots']);
        $this->assertSame(StoredMessage::make('media.capture.unreachable'), $asset->meta['capture']['reason']);
        // Without Piper: no voice, but the captions still follow the reading time.
        $this->assertNull($asset->meta['voice']);
        $this->assertSame([], $this->commandsOf('piper'));
        $this->assertNotEmpty($data['scenes'][0]['cues']);
    }

    public function test_a_failed_render_marks_only_that_language_failed(): void
    {
        $this->fakeMediaTools(failing: ['hyperframes']);
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->readyVideo()->create(['language' => 'ro']);

        RenderPresentationVideo::dispatch($repository, $this->script(), 'en', 'abc123');

        $this->assertSame(['en' => 'failed', 'ro' => 'ready'], MediaAsset::orderBy('language')->pluck('status', 'language')->map->value->all());
        $this->assertSame(StoredMessage::make(RenderPresentationVideo::FAILED_KEY), MediaAsset::firstWhere('language', 'en')->error);
        Storage::disk('local')->assertMissing("media/{$repository->id}/work-video-en");
    }

    public function test_a_job_stopped_by_the_worker_does_not_leave_the_video_running_and_runs_on_the_media_queue(): void
    {
        $repository = Repository::factory()->ready()->create();
        MediaAsset::factory()->for($repository)->create(['kind' => 'video', 'language' => 'en', 'status' => 'running']);
        $job = new RenderPresentationVideo($repository, $this->script(), 'en', 'abc123');

        $job->failed(null);

        $this->assertSame('failed', MediaAsset::sole()->status->value);
        $this->assertSame('media', $job->queue);
    }

    /**
     * A repository whose GitHub page was photographed (the fake capture writes the recorded landmarks).
     */
    private function repositoryWithCapture(): Repository
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'excusemymanners', 'name' => 'gitchronicles', 'last_sha' => str_repeat('a', 40)]);
        app(GitHubCapture::class)->capture($repository);
        $this->toolCommands = [];
        $this->toolEnvironments = [];

        return $repository;
    }

    /**
     * A script with three scenes in both languages (PresentationScript::toArray()).
     *
     * @return array<string, mixed>
     */
    private function script(): array
    {
        $source = ['label' => 'README.md', 'quote' => 'Un magazin mic.', 'url' => null];

        return [
            'scenes' => [
                ['key' => 'hook', 'kicker' => 'acme /', 'title' => 'magazin', 'heading' => null, 'quote' => null, 'source' => $source,
                    'en' => ['label' => 'What it is', 'text' => 'The shop that remembers your cart.', 'narration' => 'The shop that remembers your cart.'],
                    'ro' => ['label' => 'Ce este', 'text' => 'Magazinul care ține minte coșul.', 'narration' => 'Magazinul care ține minte coșul.']],
                ['key' => 'start', 'heading' => null, 'quote' => 'composer install', 'command' => 'composer install', 'source' => $source,
                    'en' => ['label' => 'Get started', 'text' => 'It installs fast. One command is enough.', 'narration' => 'It installs fast. One command is enough.'],
                    'ro' => ['label' => 'Cum începi', 'text' => 'Se instalează repede. O comandă e de ajuns.', 'narration' => 'Se instalează repede. O comandă e de ajuns.']],
                ['key' => 'cta', 'heading' => null, 'quote' => null, 'url' => 'github.com/acme/magazin', 'source' => null,
                    'en' => ['label' => 'Read more', 'text' => 'More on GitChronicle.', 'narration' => 'More on GitChronicle.'],
                    'ro' => ['label' => 'Mai departe', 'text' => 'Mai multe pe GitChronicle.', 'narration' => 'Mai multe pe GitChronicle.']],
            ],
            'writer' => 'ai', 'model' => 'claude-opus-5-5', 'input_tokens' => 10, 'output_tokens' => 20, 'rejected' => [], 'note' => null,
            'answer' => ['hook' => []], 'context_hash' => 'ctx',
        ];
    }
}
