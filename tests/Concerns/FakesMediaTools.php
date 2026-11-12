<?php

namespace Tests\Concerns;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Stands in for every tool of the video and the slides (the GitHub page capture, Piper, ffprobe,
 * HyperFrames, the music script, FFmpeg, the diagram script, Marp), so a test never starts Node, Python,
 * Chrome or FFmpeg and never reaches GitHub: each fake tool only writes the small file the real one would
 * write, and the commands are remembered. The fake capture writes the landmarks recorded once from a real
 * capture (tests/Fixtures/media/capture/landmarks.json) and a one-pixel tile.
 *
 * The tools' folder is a copy of the real renderers/ templates on the fake "local" disk, with the two
 * files that say "installed", and the voice is two empty files: nothing outside the fake disk is used.
 */
trait FakesMediaTools
{
    /**
     * Every command the code ran, in order.
     *
     * @var list<list<string>>
     */
    protected array $toolCommands = [];

    /**
     * The environment of every command, in the same order.
     *
     * @var list<array<string, mixed>>
     */
    protected array $toolEnvironments = [];

    /**
     * What the fake HyperFrames and Marp were given: "data" (the scenes), "composition" (index.html),
     * "deck" (the Markdown) and "theme" (the CSS).
     *
     * @var array<string, string>
     */
    protected array $toolInputs = [];

    /**
     * A 1×1 PNG, enough for getimagesize().
     */
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * Fake the tools.
     *
     * @param  list<string>  $failing  the tools that exit with an error: "capture", "hyperframes", "marp", "diagram", "piper", "ffmpeg"
     * @param  bool  $withVoice  whether Piper and its voice model look installed
     * @param  float  $clipSeconds  the length ffprobe reports for every voice clip
     */
    protected function fakeMediaTools(array $failing = [], bool $withVoice = true, float $clipSeconds = 2.5): void
    {
        $disk = Storage::disk('local');
        $renderers = $disk->path('renderers');

        foreach (['video', 'slides', 'audio', 'capture'] as $folder) {
            File::copyDirectory(base_path("renderers/{$folder}"), "{$renderers}/{$folder}");
        }

        $disk->put('renderers/node_modules/hyperframes/bin/hyperframes.mjs', '');
        $disk->put('renderers/node_modules/@marp-team/marp-cli/marp-cli.js', '');

        if ($withVoice) {
            $disk->put('voice/python', '');
            foreach (['ro', 'en'] as $language) {
                $disk->put("voice/{$language}.onnx", '');
                $disk->put("voice/{$language}.onnx.json", '{}');
            }
        }

        config([
            'gitchronicle.media.renderers_path' => $renderers,
            'gitchronicle.media.hyperframes_home' => $disk->path('hyperframes'),
            'gitchronicle.media.voice.python' => $disk->path('voice/python'),
            'gitchronicle.media.voice.models' => ['ro' => $disk->path('voice/ro.onnx'), 'en' => $disk->path('voice/en.onnx')],
            'gitchronicle.media.capture.enabled' => true,
        ]);

        Process::fake(function (PendingProcess $process) use ($failing, $clipSeconds) {
            $command = array_values((array) $process->command);
            $this->toolCommands[] = $command;
            $this->toolEnvironments[] = $process->environment;
            $tool = $this->toolOf($command);

            if (in_array($tool, $failing, true)) {
                return Process::result(output: $tool === 'capture' ? '{"error":"unreachable"}' : '{"ok":false}', errorOutput: "{$tool} broke", exitCode: 1);
            }

            if ($tool === 'loudness') {
                return Process::result(errorOutput: '{ "input_i" : "-20.00" }');
            }

            match ($tool) {
                'capture' => $this->fakeCapture($command[4]),
                'piper' => File::put($this->argumentAfter($command, '--output-file'), 'RIFF'),
                'hyperframes' => $this->fakeHyperframes($command),
                'music', 'ffmpeg' => File::put($command[array_key_last($command)], 'media'),
                'diagram' => File::put($command[3], base64_decode(self::TINY_PNG)),
                'marp' => $this->fakeMarp($command),
                default => null,
            };

            return Process::result(output: $tool === 'ffprobe' ? "{$clipSeconds}\n" : '');
        });
    }

    /**
     * Which tool a command starts.
     *
     * @param  list<string>  $command
     */
    protected function toolOf(array $command): string
    {
        $line = implode(' ', $command);

        return match (true) {
            str_contains($line, 'capture/github.mjs') => 'capture',
            str_contains($line, 'loudnorm=print_format=json') => 'loudness',
            str_contains($line, '-m piper') => 'piper',
            str_contains($command[0], 'ffprobe') => 'ffprobe',
            str_contains($line, 'hyperframes.mjs') => 'hyperframes',
            str_contains($line, 'music.mjs') => 'music',
            str_contains($line, 'diagram.mjs') => 'diagram',
            str_contains($line, 'marp-cli.js') => 'marp',
            str_contains($command[0], 'ffmpeg') => 'ffmpeg',
            default => 'unknown',
        };
    }

    /**
     * The commands of one tool, in order.
     *
     * @return list<list<string>>
     */
    protected function commandsOf(string $tool): array
    {
        return array_values(array_filter($this->toolCommands, fn (array $command): bool => $this->toolOf($command) === $tool));
    }

    /**
     * The value that follows an option on a command line.
     *
     * @param  list<string>  $command
     */
    protected function argumentAfter(array $command, string $option): string
    {
        return $command[array_search($option, $command, true) + 1];
    }

    /**
     * The capture script: the recorded landmarks, a tile, the social preview and the avatar in its output folder.
     */
    private function fakeCapture(string $folder): void
    {
        File::ensureDirectoryExists($folder);
        File::copy(base_path('tests/Fixtures/media/capture/landmarks.json'), "{$folder}/landmarks.json");

        foreach (['page-00.png', 'social.png', 'avatar.png'] as $image) {
            File::put("{$folder}/{$image}", base64_decode(self::TINY_PNG));
        }
    }

    /**
     * HyperFrames: remember the scenes and the composition it was given, write the silent video.
     *
     * @param  list<string>  $command
     */
    private function fakeHyperframes(array $command): void
    {
        $this->toolInputs['data'] = File::get($this->argumentAfter($command, '--variables-file'));
        $this->toolInputs['composition'] = File::get($this->argumentAfter($command, 'render').'/index.html');

        File::put($this->argumentAfter($command, '--output'), 'silent video');
    }

    /**
     * Marp: remember the deck and the theme it was given, write the PDF or the PPTX.
     *
     * @param  list<string>  $command
     */
    private function fakeMarp(array $command): void
    {
        $this->toolInputs['deck'] = File::get($this->argumentAfter($command, '--no-stdin'));
        $this->toolInputs['theme'] = File::get($this->argumentAfter($command, '--theme-set'));

        File::put($this->argumentAfter($command, '-o'), 'slides');
    }
}
