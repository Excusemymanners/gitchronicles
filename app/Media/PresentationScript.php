<?php

namespace App\Media;

/**
 * The script of the presentation video: its scenes in order, each with what the screen shows, what the
 * voice says and where the text comes from. ScriptWriter makes it, with Claude or without AI.
 *
 * A scene is an array with "key" (hook, problem, feature, start, proof, cta), "label" (its name on the page),
 * the texts of its kind (title, text, accent, command, chips, ...), "narration" and "source"
 * (label, quote, url) — or null for a scene built only from numbers.
 */
final readonly class PresentationScript
{
    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  string  $writer  "ai" when Claude wrote the texts, "template" when they were built without AI
     * @param  list<array{scene: string, reason: string}>  $rejected  the AI scenes that did not pass the checks, and why
     * @param  string|null  $note  why the template was used (AI off, AI failed, an unusable answer)
     */
    public function __construct(
        public array $scenes,
        public string $writer,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public array $rejected = [],
        public ?string $note = null,
    ) {}

    /**
     * The script as plain data, for the jobs and for media_assets.meta.
     *
     * @return array{scenes: list<array<string, mixed>>, writer: string, model: string|null, input_tokens: int, output_tokens: int, rejected: list<array{scene: string, reason: string}>, note: string|null}
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
        ];
    }
}
