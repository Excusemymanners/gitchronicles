<?php

namespace App\Media\Speech;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The neural engines run as one Python process for all the sentences of a video (renderers/tts/speak.py):
 * the sentences go on the standard input as a JSON list of {text, path}, never on the command line.
 */
trait RunsSpeakScript
{
    /**
     * Run speak.py and check that every clip was written.
     *
     * @param  list<string>  $command  the Python, the script, the engine and its options
     * @param  array<string, string>  $clips  the WAV path of each clip => its text
     * @param  array<string, array{rate: float, pitch: float}>  $shapes  the delivery of each clip by its path (sent along with the text)
     *
     * @throws RuntimeException when the engine fails or a clip is missing
     */
    private function runSpeakScript(array $command, array $clips, array $shapes, string $engine, int $secondsPerClip): void
    {
        $list = [];

        foreach ($clips as $path => $text) {
            $list[] = ['text' => $text, 'path' => $path, 'rate' => $shapes[$path]['rate'] ?? 0.0, 'pitch' => $shapes[$path]['pitch'] ?? 0.0];
        }

        $result = Process::timeout(60 + $secondsPerClip * count($clips))
            ->input(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->run($command);

        foreach (array_keys($clips) as $path) {
            if ($result->failed() || ! is_file($path)) {
                throw new RuntimeException("{$engine} could not read the text: ".mb_substr(trim($result->errorOutput()), -500));
            }
        }
    }
}
