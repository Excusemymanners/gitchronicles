<?php

namespace App\Media;

use App\Enums\MediaLanguage;

/**
 * The script of the presentation video: its scenes in order, written once for every language.
 * ScriptWriter makes it, with Claude or without AI.
 *
 * A scene is an array with what the languages share — "key" (hook, problem, feature, built, proof,
 * start, cta), its anchor in the README ("heading", "quote"), its "source" (label, quote, url; null for
 * a scene with no text from the repository) and data like "command" or "stars" — and, under each
 * language code ("en", "ro"), what is said and shown in that language: "label", "text", "narration"
 * and, for some scenes, "title", "chips", "stats", "note".
 *
 * scenesFor() gives the scenes of one language as flat arrays: what the video of that language, the
 * page and the slides read.
 */
final readonly class PresentationScript
{
    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  string  $writer  "ai" when Claude wrote the texts, "template" when they were built without AI
     * @param  list<array{scene: string, reason: string}>  $rejected  the AI scenes that did not pass the checks, and why
     * @param  string|null  $note  why the template was used (AI off, AI failed, an unusable answer)
     * @param  array<string, mixed>|null  $answer  Claude's decoded answer, kept so the same texts can be used again without a new call
     * @param  string|null  $contextHash  the hash of what Claude was given: the same hash means the same answer can be reused
     */
    public function __construct(
        public array $scenes,
        public string $writer,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public array $rejected = [],
        public ?string $note = null,
        public ?array $answer = null,
        public ?string $contextHash = null,
    ) {}

    /**
     * The scenes of one language, each as one flat array: the shared fields and that language's texts.
     *
     * @return list<array<string, mixed>>
     */
    public function scenesFor(MediaLanguage $language): array
    {
        return self::flatten($this->scenes, $language);
    }

    /**
     * Flatten scenes kept as plain data (a queued job has the script as an array).
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $scenes, MediaLanguage $language): array
    {
        $codes = array_flip(array_column(MediaLanguage::cases(), 'value'));

        return array_map(
            fn (array $scene): array => [...array_diff_key($scene, $codes), ...($scene[$language->value] ?? [])],
            array_values($scenes),
        );
    }

    /**
     * The script as plain data, for the jobs and for media_assets.meta.
     *
     * @return array{scenes: list<array<string, mixed>>, writer: string, model: string|null, input_tokens: int, output_tokens: int, rejected: list<array{scene: string, reason: string}>, note: string|null, answer: array<string, mixed>|null, context_hash: string|null}
     */
    public function toArray(): array
    {
        return [
            'scenes' => $this->scenes,
            'writer' => $this->writer,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'rejected' => $this->rejected,
            'note' => $this->note,
            'answer' => $this->answer,
            'context_hash' => $this->contextHash,
        ];
    }
}
