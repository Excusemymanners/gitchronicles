<?php

namespace App\Media;

use App\Enums\MediaLanguage;
use App\Media\Speech\SpeechDelivery;
use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Makes the presentation video of a repository in one language from its script, in a work folder
 * (media/{id}/work-video-{language} on the "local" disk) that is deleted at the end:
 *
 * 1. the camera: what each scene shows on the photographed GitHub page (SceneDirector);
 * 2. the voice: one clip per sentence, read by the language's voice (Narrator), measured with ffprobe;
 * 3. the timeline: every scene as long as its voice, with the caption phrases and the moments of the
 *    camera shots (VideoTimeline);
 * 4. the picture: a copy of the HyperFrames composition (renderers/video) with the photo of the page
 *    next to it and the total length written on its root, the scenes in data.json, rendered by
 *    `hyperframes render` to a silent MP4. Without a photo the same composition draws typographic scenes;
 * 5. the sound: the generated music (renderers/audio/music.mjs) and the voice at an even loudness,
 *    mixed by AudioMixer;
 * 6. the poster (a frame of the opening scene) and the captions (Captions, WebVTT).
 *
 * The finished files replace the old ones in media/{id}: video-{language}.mp4, poster-{language}.jpg,
 * captions-{language}.vtt. Each language has its own work folder, so two languages can render at once.
 */
final class VideoRenderer
{
    /**
     * The fields of a scene that the composition reads (renderers/video/index.html); the rest
     * (narration, source) stays on the server.
     */
    private const COMPOSITION_FIELDS = [
        'key', 'label', 'start', 'duration', 'kicker', 'title', 'text', 'index', 'count',
        'command', 'stars', 'chips', 'stats', 'modules', 'note', 'url', 'credit', 'shots', 'cues',
    ];

    /**
     * The folder of the page's photo inside the composition.
     */
    private const CAPTURE_FOLDER = 'capture';

    /**
     * The longest render, in seconds (Chrome captures every frame).
     */
    public const RENDER_TIMEOUT_SECONDS = 900;

    /**
     * Create the renderer.
     */
    public function __construct(private AudioMixer $mixer) {}

    /**
     * Render the video and return what the job saves.
     *
     * @param  list<array<string, mixed>>  $scenes  the script's scenes of this language (PresentationScript::scenesFor())
     * @param  PageCapture  $capture  the photo of the GitHub page, or a missing one (typographic scenes)
     * @return array{files: array{mp4: string, poster: string, vtt: string}, duration_ms: int, size_bytes: int, scenes: list<array<string, mixed>>, voice: string|null, render_ms: int}
     *
     * @throws RuntimeException when a tool fails
     */
    public function render(Repository $repository, array $scenes, MediaLanguage $language, PageCapture $capture): array
    {
        $startedAt = microtime(true);
        $disk = Storage::disk('local');
        $directory = $repository->mediaDirectory();
        $work = "{$directory}/work-video-{$language->value}";
        $narrator = Narrator::for($language);

        $disk->deleteDirectory($work);
        $disk->makeDirectory($work);
        $workPath = $disk->path($work);

        try {
            $scenes = SceneDirector::direct($scenes, $capture);
            [$voices, $clipFiles] = $this->speak($scenes, $narrator, $workPath);
            $timeline = VideoTimeline::plan($scenes, $voices, (float) config('gitchronicle.media.max_seconds'));

            $this->renderPicture($timeline, $workPath, $repository, $language, $capture);

            // No music bed by default (MEDIA_MUSIC=true brings it back).
            $music = null;

            if (config('gitchronicle.media.music')) {
                $music = "{$workPath}/music.wav";
                $this->run([config('gitchronicle.media.node_bin'), $this->renderers('audio/music.mjs'), (string) $timeline['total'], $music], 120, 'music');
            }

            $clips = $this->placedClips($timeline['scenes'], $clipFiles);
            $gain = $this->mixer->voiceGain(array_column($clips, 'path'));
            $this->mixer->mix("{$workPath}/silent.mp4", $clips, $music, $timeline['total'], "{$workPath}/video.mp4", $gain);

            // The poster is the opening while the camera still stands on the repository's name.
            $this->mixer->poster("{$workPath}/video.mp4", min(1.2, max(0.5, $timeline['scenes'][0]['duration'] - 0.3)), "{$workPath}/poster.jpg");
            File::put("{$workPath}/captions.vtt", Captions::webVtt($timeline['scenes']));

            $files = [
                'mp4' => "{$directory}/video-{$language->value}.mp4",
                'poster' => "{$directory}/poster-{$language->value}.jpg",
                'vtt' => "{$directory}/captions-{$language->value}.vtt",
            ];

            foreach (['mp4' => 'video.mp4', 'poster' => 'poster.jpg', 'vtt' => 'captions.vtt'] as $format => $name) {
                File::move("{$workPath}/{$name}", $disk->path($files[$format]));
            }

            return [
                'files' => $files,
                'duration_ms' => (int) round($timeline['total'] * 1000),
                'size_bytes' => (int) $disk->size($files['mp4']),
                // The page needs the scenes with their times and sources, not the camera and the word timings.
                'scenes' => array_map(fn (array $scene): array => array_diff_key($scene, ['shots' => true, 'cues' => true, 'sentences' => true]), $timeline['scenes']),
                'voice' => $clips === [] ? null : $narrator->label(),
                'render_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } finally {
            $disk->deleteDirectory($work);
        }
    }

    /**
     * One voice clip per sentence of every scene, all read in one go (a neural engine loads its model
     * once); without any voice installed, no clips at all (the video then has music and captions only).
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return array{0: list<list<array{text: string, seconds: float}>|null>, 1: array<string, string>} the sentences of each scene with their lengths, and the clip file of each "scene-sentence" key
     */
    private function speak(array $scenes, Narrator $narrator, string $workPath): array
    {
        if (! $narrator->isAvailable()) {
            Log::info('No voice is installed: the video is made without a voice.');

            return [array_fill(0, count($scenes), null), []];
        }

        $clips = [];
        $shapes = [];
        $delivery = SpeechDelivery::shapesFor(
            array_map(fn (array $scene): array => Captions::sentences((string) ($scene['narration'] ?? '')), $scenes),
            array_column($scenes, 'key'),
        );

        foreach ($scenes as $position => $scene) {
            foreach (Captions::sentences((string) ($scene['narration'] ?? '')) as $number => $sentence) {
                $clips["{$workPath}/voice-{$position}-{$number}.wav"] = $sentence;
                $shapes["{$workPath}/voice-{$position}-{$number}.wav"] = $delivery[$position][$number];
            }
        }

        $narrator->speak($clips, $shapes);

        $voices = [];
        $files = [];

        foreach ($scenes as $position => $scene) {
            $voice = [];

            foreach (Captions::sentences((string) ($scene['narration'] ?? '')) as $number => $sentence) {
                $path = "{$workPath}/voice-{$position}-{$number}.wav";
                $this->mixer->trimSilence($path);
                $files[self::clipKey($scene, $sentence)] = $path;
                $voice[] = ['text' => $sentence, 'seconds' => $this->mixer->duration($path)];
            }

            $voices[] = $voice === [] ? null : $voice;
        }

        return [$voices, $files];
    }

    /**
     * Each voice clip with the second it starts at, for the scenes the timeline kept.
     *
     * @param  list<array<string, mixed>>  $scenes  the planned scenes
     * @param  array<string, string>  $clipFiles
     * @return list<array{path: string, at: float}>
     */
    private function placedClips(array $scenes, array $clipFiles): array
    {
        $clips = [];

        foreach ($scenes as $scene) {
            if ($scene['voice_at'] === null) {
                continue;
            }

            foreach ($scene['sentences'] as $sentence) {
                $path = $clipFiles[self::clipKey($scene, $sentence['text'])] ?? null;

                if ($path !== null) {
                    $clips[] = ['path' => $path, 'at' => $sentence['start']];
                }
            }
        }

        return $clips;
    }

    /**
     * What identifies a sentence's clip, whatever scenes the timeline left out: the scene's kind, its
     * whole narration and the sentence itself.
     *
     * @param  array<string, mixed>  $scene
     */
    private static function clipKey(array $scene, string $sentence): string
    {
        return $scene['key'].'|'.md5(($scene['narration'] ?? '').'|'.$sentence);
    }

    /**
     * The silent video: a copy of the composition with its length set and the photo of the page inside,
     * the scenes in data.json, and `hyperframes render` (with --quiet --json it prints nothing on success,
     * one JSON error line on failure).
     *
     * @param  array{scenes: list<array<string, mixed>>, total: float}  $timeline
     */
    private function renderPicture(array $timeline, string $workPath, Repository $repository, MediaLanguage $language, PageCapture $capture): void
    {
        $composition = "{$workPath}/composition";
        File::copyDirectory($this->renderers('video'), $composition);

        if ($capture->isAvailable()) {
            File::copyDirectory((string) $capture->directory, "{$composition}/".self::CAPTURE_FOLDER);
        }

        $html = File::get("{$composition}/index.html");
        $html = preg_replace('/(<div id="root"[^>]*?data-duration=")[0-9.]+(")/', '${1}'.$timeline['total'].'${2}', $html, 1, $count);

        if ($count !== 1) {
            throw new RuntimeException('The composition has no root element with data-duration.');
        }

        File::put("{$composition}/index.html", $html);

        $data = [
            'language' => $language->value,
            'repo' => ['owner' => $repository->owner, 'name' => $repository->name],
            'capture' => $capture->forComposition(self::CAPTURE_FOLDER),
            'scenes' => array_map(fn (array $scene): array => array_intersect_key($scene, array_flip(self::COMPOSITION_FIELDS)), $timeline['scenes']),
        ];
        File::put("{$workPath}/data.json", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->run([
            config('gitchronicle.media.node_bin'), $this->renderers('node_modules/hyperframes/bin/hyperframes.mjs'),
            'render', $composition,
            '--variables-file', "{$workPath}/data.json",
            '--output', "{$workPath}/silent.mp4",
            '--format', 'mp4', '--fps', (string) VideoTimeline::FPS, '--quality', 'looks', '--crf', (string) $this->mixer->masterQuality(),
            '--workers', (string) config('gitchronicle.media.workers'),
            '--quiet', '--json',
        ], self::RENDER_TIMEOUT_SECONDS, 'hyperframes');
    }

    /**
     * Run one tool with the isolated environment (ToolEnvironment); log its output and throw when it fails.
     *
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout, string $tool): void
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
