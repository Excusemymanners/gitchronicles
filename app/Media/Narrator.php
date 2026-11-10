<?php

namespace App\Media;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The Romanian voice of the video: Piper TTS (renderers/tts, `composer media:install`) with the
 * ro_RO-mihai-medium model. It runs on this computer, without internet, one short clip per scene.
 *
 * The text goes on the standard input, never on the command line, and Piper writes a WAV file.
 * When Piper or the model is not installed, the video is made without a voice (music and captions only).
 */
final class Narrator
{
    /**
     * Create the narrator with the paths from config('gitchronicle.media.voice').
     */
    public function __construct(
        private string $python,
        private string $model,
        private float $lengthScale,
    ) {}

    /**
     * Make the narrator from the configuration.
     */
    public static function fromConfig(): self
    {
        $voice = config('gitchronicle.media.voice');

        return new self($voice['python'], $voice['model'], (float) $voice['length_scale']);
    }

    /**
     * Whether Piper and the voice model are installed.
     */
    public function isAvailable(): bool
    {
        return is_file($this->python) && is_file($this->model) && is_file($this->model.'.json');
    }

    /**
     * The voice's name, shown on the page.
     */
    public function label(): string
    {
        return 'Piper · '.basename($this->model, '.onnx');
    }

    /**
     * Read the text aloud into a WAV file.
     *
     * @throws RuntimeException when Piper fails
     */
    public function speak(string $text, string $wavPath): void
    {
        $result = Process::timeout(120)
            ->input($text)
            ->run([
                $this->python, '-m', 'piper',
                '--model', $this->model,
                '--output-file', $wavPath,
                '--length-scale', (string) $this->lengthScale,
                '--sentence-silence', '0.15',
            ]);

        if ($result->failed() || ! is_file($wavPath)) {
            throw new RuntimeException('Piper could not read the text: '.trim($result->errorOutput()));
        }
    }
}
