<?php

namespace App\Media;

use App\Enums\MediaLanguage;
use App\Media\Speech\EdgeVoice;
use App\Media\Speech\KokoroVoice;
use App\Media\Speech\PiperVoice;
use App\Media\Speech\SpeechSynthesizer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The voice of the video in one language. Which engine speaks is one line of configuration per language
 * (config gitchronicle.media.voice.engines, MEDIA_VOICE_EN / MEDIA_VOICE_RO), written "engine:voice":
 *
 *   piper:en_US-ryan-high         Piper, offline, on the processor (renderers/tts/voices/{voice}.onnx)
 *   kokoro:am_michael             Kokoro-82M, offline neural voice, English only
 *   edge:ro-RO-EmilNeural         Microsoft Edge's online voices — optional and unofficial (EdgeVoice)
 *
 * Piper with the language's own model (voice.models) is always the fallback: when the chosen engine is not
 * installed or fails (an online voice without internet), the whole video is read by Piper instead, so one
 * video never mixes two voices. The voice receives the text respelled for its language (SpokenText::for());
 * the captions keep the real spelling. Without any voice installed the video has music and captions only.
 */
final class Narrator
{
    /**
     * The voice that read the last video (the chosen one or the fallback).
     */
    private ?SpeechSynthesizer $used = null;

    /**
     * Create the narrator of a language with its chosen voice and the fallback (null when the chosen voice
     * already is the language's Piper model).
     */
    public function __construct(
        private MediaLanguage $language,
        private SpeechSynthesizer $voice,
        private ?SpeechSynthesizer $fallback = null,
    ) {}

    /**
     * Make the narrator of a language from the configuration.
     */
    public static function for(MediaLanguage $language): self
    {
        $config = config('gitchronicle.media.voice');
        $fallback = new PiperVoice(
            (string) $config['python'],
            (string) ($config['models'][$language->value] ?? ''),
            (float) ($config['length_scale'][$language->value] ?? 1.0),
        );
        $voice = self::voice((string) ($config['engines'][$language->value] ?? 'piper'), $language, $config);

        return new self($language, $voice ?? $fallback, $voice === null ? null : $fallback);
    }

    /**
     * The voice an "engine:voice" setting names, or null for the language's own Piper model ("piper").
     *
     * @param  array<string, mixed>  $config  config('gitchronicle.media.voice')
     */
    public static function voice(string $setting, MediaLanguage $language, array $config): ?SpeechSynthesizer
    {
        [$engine, $name] = array_pad(explode(':', trim($setting), 2), 2, '');
        $python = (string) $config['python'];
        $script = (string) $config['script'];

        return match ($engine) {
            'kokoro' => new KokoroVoice($python, $script, (string) $config['kokoro']['model'], (string) $config['kokoro']['voices'], $name ?: 'am_michael', (float) $config['kokoro']['speed']),
            'edge' => new EdgeVoice($python, $script, $name, (string) config('gitchronicle.media.ffmpeg_path'), (string) $config['edge']['rate']),
            default => $name === '' ? null : new PiperVoice($python, rtrim((string) $config['voices_path'], '/')."/{$name}.onnx", (float) ($config['length_scale'][$language->value] ?? 1.0)),
        };
    }

    /**
     * Whether any voice of this language is installed.
     */
    public function isAvailable(): bool
    {
        return $this->voice->isAvailable() || ($this->fallback?->isAvailable() ?? false);
    }

    /**
     * The name of the voice that read the video (before it, the chosen one), shown on the page.
     */
    public function label(): string
    {
        return ($this->used ?? $this->voice)->label();
    }

    /**
     * Read every sentence into its WAV file: with the chosen voice, or all of them with the fallback when
     * the chosen one is missing or fails.
     *
     * @param  array<string, string>  $clips  the WAV path of each clip => the sentence as the captions show it
     *
     * @throws RuntimeException when no voice could read them
     */
    public function speak(array $clips): void
    {
        $spoken = array_map(fn (string $text): string => SpokenText::for($text, $this->language), $clips);
        $failure = null;

        foreach (array_filter([$this->voice, $this->fallback]) as $voice) {
            if (! $voice->isAvailable()) {
                continue;
            }

            try {
                $voice->speak($spoken);
                $this->used = $voice;

                return;
            } catch (RuntimeException $exception) {
                Log::warning('A voice failed; the next one reads the video.', ['voice' => $voice->label(), 'message' => $exception->getMessage()]);
                $failure = $exception;
            }
        }

        throw $failure ?? new RuntimeException("No voice is installed for {$this->language->value}.");
    }
}
