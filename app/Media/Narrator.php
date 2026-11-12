<?php

namespace App\Media;

use App\Enums\MediaLanguage;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The voice of the video in one language: Piper TTS (renderers/tts, `composer media:install`) with that
 * language's model — ro_RO-mihai-medium for Romanian, en_US-ljspeech-high for English. It runs on this
 * computer, without internet, one short clip per sentence (so the captions know exactly when every
 * sentence starts and ends).
 *
 * The text goes on the standard input, never on the command line, and Piper writes a WAV file. The voice
 * receives the text respelled for its language where needed (SpokenText::for()); the captions keep the
 * real spelling. When Piper or the model is not installed, the video is made without a voice (music and
 * captions only).
 */
final class Narrator
{
    /**
     * Create the narrator of one language with the paths from config('gitchronicle.media.voice').
     */
    public function __construct(
        private MediaLanguage $language,
        private string $python,
        private string $model,
        private float $lengthScale,
    ) {}

    /**
     * Make the narrator of a language from the configuration.
     */
    public static function for(MediaLanguage $language): self
    {
        $voice = config('gitchronicle.media.voice');

        return new self(
            $language,
            (string) $voice['python'],
            (string) ($voice['models'][$language->value] ?? ''),
            (float) ($voice['length_scale'][$language->value] ?? 1.0),
        );
    }

    /**
     * Whether Piper and the voice model of this language are installed.
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
            ->input(SpokenText::for($text, $this->language))
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
