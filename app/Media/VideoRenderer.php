<?php

namespace App\Media;

use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Makes the presentation video of a repository from its script, in a work folder
 * (media/{id}/work-video on the "local" disk) that is deleted at the end:
 *
 * 1. the voice: one Piper clip per scene (Narrator), measured with ffprobe;
 * 2. the timeline: every scene as long as its voice (VideoTimeline);
 * 3. the picture: a copy of the HyperFrames composition (renderers/video) with the total length written
 *    on its root, the scenes in data.json, rendered by `hyperframes render` to a silent MP4;
 * 4. the sound: the generated music (renderers/audio/music.mjs) and the voice, mixed by AudioMixer;
 * 5. the poster (a frame of the opening scene) and the captions (Captions, WebVTT).
 *
 * The finished files replace the old ones in media/{id}: video.mp4, poster.jpg, captions.vtt.
 */
final class VideoRenderer
{
    /**
     * The fields of a scene that the composition reads (renderers/video/index.html); the rest
     * (narration, source) stays on the server.
     */
    private const COMPOSITION_FIELDS = [
        'key', 'label', 'start', 'duration', 'kicker', 'title', 'text', 'accent', 'index', 'count',
        'command', 'stars', 'chips', 'note', 'url',
    ];

    /**
     * The longest render, in seconds (Chrome captures every frame).
     */
    public const RENDER_TIMEOUT_SECONDS = 600;

    /**
     * Create the renderer.
     */
    public function __construct(
        private Narrator $narrator,
        private AudioMixer $mixer,
    ) {}

    /**
     * Render the video and return what the job saves.
     *
     * @param  list<array<string, mixed>>  $scenes  the script's scenes (PresentationScript)
     * @return array{files: array{mp4: string, poster: string, vtt: string}, duration_ms: int, size_bytes: int, scenes: list<array<string, mixed>>, voice: string|null, render_ms: int}
     *
     * @throws RuntimeException when a tool fails
     */
    public function render(Repository $repository, array $scenes): array
    {
        $startedAt = microtime(true);
        $disk = Storage::disk('local');
        $directory = $repository->mediaDirectory();
        $work = "{$directory}/work-video";

        $disk->deleteDirectory($work);
        $disk->makeDirectory($work);
        $workPath = $disk->path($work);

        try {
            [$scenes, $voiceSeconds] = $this->speak($scenes, $workPath);
            $timeline = VideoTimeline::plan($scenes, $voiceSeconds);

            $this->renderPicture($timeline, $workPath, $repository);

            $music = "{$workPath}/music.wav";
            $this->run([config('gitchronicle.media.node_bin'), $this->renderers('audio/music.mjs'), (string) $timeline['total'], $music], 120, 'music', $workPath);

            $clips = array_values(array_filter(array_map(
                fn (array $scene): ?array => isset($scene['voice_file']) ? ['path' => $scene['voice_file'], 'at' => $scene['voice_at']] : null,
                $timeline['scenes'],
            )));
            $this->mixer->mix("{$workPath}/silent.mp4", $clips, $music, $timeline['total'], "{$workPath}/video.mp4");

            $hook = $timeline['scenes'][0];
            $this->mixer->poster("{$workPath}/video.mp4", min(2.4, max(0.5, $hook['duration'] - 0.3)), "{$workPath}/poster.jpg");
            File::put("{$workPath}/captions.vtt", Captions::webVtt($timeline['scenes']));

            $files = ['mp4' => "{$directory}/video.mp4", 'poster' => "{$directory}/poster.jpg", 'vtt' => "{$directory}/captions.vtt"];

            foreach (['video.mp4', 'poster.jpg', 'captions.vtt'] as $name) {
                File::move("{$workPath}/{$name}", $disk->path("{$directory}/{$name}"));
            }

            return [
                'files' => $files,
                'duration_ms' => (int) round($timeline['total'] * 1000),
                'size_bytes' => (int) $disk->size($files['mp4']),
                'scenes' => array_map(fn (array $scene): array => array_diff_key($scene, ['voice_file' => true]), $timeline['scenes']),
                'voice' => in_array(true, array_map(fn (?float $seconds): bool => $seconds !== null, $voiceSeconds), true) ? $this->narrator->label() : null,
                'render_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } finally {
            $disk->deleteDirectory($work);
        }
    }

    /**
     * One voice clip per scene that has words to say; without Piper, no clips at all (the video then has
     * music and captions only). Each scene keeps the path of its clip in "voice_file".
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return array{0: list<array<string, mixed>>, 1: list<float|null>}
     */
    private function speak(array $scenes, string $workPath): array
    {
        if (! $this->narrator->isAvailable()) {
            Log::info('Piper is not installed: the video is made without a voice.');

            return [$scenes, array_fill(0, count($scenes), null)];
        }

        $seconds = [];

        foreach ($scenes as $position => $scene) {
            $text = trim((string) ($scene['narration'] ?? ''));

            if ($text === '') {
                $seconds[] = null;

                continue;
            }

            $path = "{$workPath}/voice-{$position}.wav";
            $this->narrator->speak($text, $path);
            $scenes[$position]['voice_file'] = $path;
            $seconds[] = $this->mixer->duration($path);
        }

        return [$scenes, $seconds];
    }

    /**
     * The silent video: a copy of the composition with its length set, the scenes in data.json, and
     * `hyperframes render` (with --quiet --json it prints nothing on success, one JSON error line on failure).
     *
     * @param  array{scenes: list<array<string, mixed>>, total: float}  $timeline
     */
    private function renderPicture(array $timeline, string $workPath, Repository $repository): void
    {
        $composition = "{$workPath}/composition";
        File::copyDirectory($this->renderers('video'), $composition);

        $html = File::get("{$composition}/index.html");
        $html = preg_replace('/(<div id="root"[^>]*?data-duration=")[0-9.]+(")/', '${1}'.$timeline['total'].'${2}', $html, 1, $count);

        if ($count !== 1) {
            throw new RuntimeException('The composition has no root element with data-duration.');
        }

        File::put("{$composition}/index.html", $html);

        $data = [
            'repo' => ['owner' => $repository->owner, 'name' => $repository->name],
            'scenes' => array_map(fn (array $scene): array => array_intersect_key($scene, array_flip(self::COMPOSITION_FIELDS)), $timeline['scenes']),
        ];
        File::put("{$workPath}/data.json", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->run([
            config('gitchronicle.media.node_bin'), $this->renderers('node_modules/hyperframes/bin/hyperframes.mjs'),
            'render', $composition,
            '--variables-file', "{$workPath}/data.json",
            '--output', "{$workPath}/silent.mp4",
            '--format', 'mp4', '--fps', (string) VideoTimeline::FPS, '--quality', 'looks',
            '--workers', (string) config('gitchronicle.media.workers'),
            '--quiet', '--json',
        ], self::RENDER_TIMEOUT_SECONDS, 'hyperframes', $workPath);
    }

    /**
     * Run one tool with the isolated environment (ToolEnvironment); log its output and throw when it fails.
     *
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout, string $tool, string $workPath): void
    {
        $result = Process::timeout($timeout)
            ->env(ToolEnvironment::variables())
            ->run($command);

        if ($result->failed()) {
            Log::warning("Media tool failed: {$tool}.", [
                'exit_code' => $result->exitCode(),
                'output' => mb_substr($result->output(), -2000),
                'error_output' => mb_substr($result->errorOutput(), -2000),
            ]);

            throw new RuntimeException("{$tool} failed with exit code {$result->exitCode()}.");
        }
    }

    /**
     * A path inside renderers/.
     */
    private function renderers(string $path): string
    {
        return rtrim((string) config('gitchronicle.media.renderers_path'), '/').'/'.$path;
    }
}
