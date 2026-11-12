<?php

namespace App\Media\Speech;

/**
 * OPTIONAL, ONLINE: the free neural voices of Microsoft Edge's "Read aloud", through the edge-tts package
 * (renderers/tts/speak.py). Natural male voices in both languages ("en-US-AndrewMultilingualNeural",
 * "ro-RO-EmilNeural"), but the service is unofficial: there is no contract, it can change or stop at any
 * time, and the sentences are sent to Microsoft. When it fails, Narrator falls back to Piper.
 */
final class EdgeVoice implements SpeechSynthesizer
{
    use RunsSpeakScript;

    /**
     * Create the voice.
     *
     * @param  string  $rate  the speed as edge-tts writes it ("+0%", "+8%")
     */
    public function __construct(
        private string $python,
        private string $script,
        private string $voice,
        private string $ffmpeg,
        private string $rate = '+0%',
    ) {}

    public function isAvailable(): bool
    {
        return is_file($this->python) && is_file($this->script);
    }

    public function label(): string
    {
        return 'Edge (online) · '.$this->voice;
    }

    public function speak(array $clips, array $shapes = []): void
    {
        $this->runSpeakScript(
            [$this->python, $this->script, 'edge', '--voice', $this->voice, '--rate', $this->rate, '--ffmpeg', $this->ffmpeg],
            $clips, $shapes, 'Edge', 15,
        );
    }
}
