<?php

namespace App\Media\Speech;

/**
 * OPTIONAL, ENGLISH, NEEDS A GPU: Chatterbox (MIT), the one free local engine with an emotion control
 * ("exaggeration": 0.25 flat, 0.5 normal, 1.0 very expressive). It is not installed by default and is never
 * the default voice: it runs with the Python of a separate environment (config
 * gitchronicle.media.voice.chatterbox.python) because it needs about 6 GB of packages and models. Set it
 * with MEDIA_VOICE_EN=chatterbox:0.7. It has no speed or pitch control, so the delivery shapes are ignored;
 * its own expressiveness replaces them. About 0.8 s of work per second of speech on an RTX 4080.
 */
final class ChatterboxVoice implements SpeechSynthesizer
{
    use RunsSpeakScript;

    /**
     * Create the voice.
     *
     * @param  string  $python  the Python of the Chatterbox environment
     * @param  string  $script  renderers/tts/speak.py
     * @param  float  $exaggeration  how expressive it is, 0.25 to 1.0
     */
    public function __construct(
        private string $python,
        private string $script,
        private float $exaggeration = 0.5,
    ) {}

    public function isAvailable(): bool
    {
        return is_file($this->python) && is_file($this->script);
    }

    public function label(): string
    {
        return 'Chatterbox · '.$this->exaggeration;
    }

    public function speak(array $clips, array $shapes = []): void
    {
        $this->runSpeakScript(
            [$this->python, $this->script, 'chatterbox', '--exaggeration', (string) $this->exaggeration],
            $clips, [], 'Chatterbox', 30,
        );
    }
}
