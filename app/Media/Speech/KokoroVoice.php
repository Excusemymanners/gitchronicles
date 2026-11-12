<?php

namespace App\Media\Speech;

/**
 * Kokoro-82M (Apache-2.0), a small neural voice with natural rhythm, run offline through kokoro-onnx by
 * renderers/tts/speak.py. English only (American and British voices): "am_michael", "bm_george", …
 * All the sentences of a video go to one process, so the model is loaded once.
 */
final class KokoroVoice implements SpeechSynthesizer
{
    use RunsSpeakScript;

    /**
     * Create the voice.
     *
     * @param  string  $python  the Python of renderers/tts/.venv
     * @param  string  $script  renderers/tts/speak.py
     * @param  string  $model  kokoro-v1.0.onnx
     * @param  string  $voices  voices-v1.0.bin (every voice's style vector)
     * @param  string  $voice  the voice's name; its first letter is the accent: "a" American, "b" British
     */
    public function __construct(
        private string $python,
        private string $script,
        private string $model,
        private string $voices,
        private string $voice,
        private float $speed = 1.0,
    ) {}

    public function isAvailable(): bool
    {
        return is_file($this->python) && is_file($this->script) && is_file($this->model) && is_file($this->voices);
    }

    public function label(): string
    {
        return 'Kokoro · '.$this->voice;
    }

    public function speak(array $clips, array $shapes = []): void
    {
        $this->runSpeakScript([
            $this->python, $this->script, 'kokoro',
            '--model', $this->model, '--voices', $this->voices,
            '--voice', $this->voice, '--speed', (string) $this->speed,
            '--lang', str_starts_with($this->voice, 'b') ? 'en-gb' : 'en-us',
        ], $clips, $shapes, 'Kokoro', 20);
    }
}
