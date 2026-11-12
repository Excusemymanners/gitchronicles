<?php

namespace App\Media\Speech;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Piper TTS (GPL, run as a separate program): small, fast, offline, on the processor. Each model is one
 * voice of one language ("ro_RO-mihai-medium", "en_US-ryan-high"), downloaded by renderers/tts/install.sh.
 * It is the voice that is always there: the fallback of every other engine.
 */
final class PiperVoice implements SpeechSynthesizer
{
    /**
     * Create the voice.
     *
     * @param  string  $python  the Python of renderers/tts/.venv
     * @param  string  $model  the .onnx file of the voice (its .onnx.json sits next to it)
     * @param  float  $lengthScale  below 1 speaks faster
     */
    public function __construct(
        private string $python,
        private string $model,
        private float $lengthScale = 1.0,
    ) {}

    public function isAvailable(): bool
    {
        return is_file($this->python) && is_file($this->model) && is_file($this->model.'.json');
    }

    public function label(): string
    {
        return 'Piper · '.basename($this->model, '.onnx');
    }

    /**
     * One Piper process per sentence; the text goes on the standard input, never on the command line.
     */
    public function speak(array $clips): void
    {
        foreach ($clips as $wavPath => $text) {
            $result = Process::timeout(120)
                ->input($text)
                ->run([
                    $this->python, '-m', 'piper',
                    '--model', $this->model,
                    '--output-file', $wavPath,
                    '--length-scale', (string) $this->lengthScale,
                    '--sentence-silence', '0.05',
                ]);

            if ($result->failed() || ! is_file($wavPath)) {
                throw new RuntimeException('Piper could not read the text: '.trim($result->errorOutput()));
            }
        }
    }
}
