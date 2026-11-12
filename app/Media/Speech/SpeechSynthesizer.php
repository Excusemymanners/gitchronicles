<?php

namespace App\Media\Speech;

use RuntimeException;

/**
 * A voice that reads the video's sentences aloud: one engine (Piper, Kokoro, Edge) with one of its voices.
 * Narrator picks the driver of each language from config('gitchronicle.media.voice.engines'), the same
 * idea as App\Ai\TextGenerator: the rest of the video does not know which engine speaks.
 */
interface SpeechSynthesizer
{
    /**
     * Whether the engine and the voice are installed (or, for an online voice, configured).
     */
    public function isAvailable(): bool;

    /**
     * The voice's name, shown on the page ("Kokoro · am_michael").
     */
    public function label(): string;

    /**
     * Read every text aloud into its WAV file, one clip per sentence (so the captions know exactly when
     * every sentence starts and ends).
     *
     * @param  array<string, string>  $clips  the WAV path of each clip => the text it says (already respelled for the voice)
     *
     * @throws RuntimeException when the engine fails
     */
    public function speak(array $clips): void;
}
