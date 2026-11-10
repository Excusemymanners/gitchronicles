<?php

namespace App\Documentation;

use App\Ai\AiResult;
use App\Models\Document;

/**
 * The Markdown of one page and who wrote it: Claude (with the model, the driver and what the call
 * consumed) or the template (no AI, nothing consumed).
 */
final readonly class WrittenPage
{
    /**
     * @param  string  $writer  Document::WRITER_AI or Document::WRITER_TEMPLATE
     */
    public function __construct(
        public string $markdown,
        public string $writer,
        public ?string $model = null,
        public ?string $aiDriver = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $costUsdEquivalent = null,
    ) {}

    /**
     * A page written by the template.
     */
    public static function fromTemplate(string $markdown): self
    {
        return new self($markdown, Document::WRITER_TEMPLATE);
    }

    /**
     * A page written by Claude, with the Markdown already cleaned up.
     */
    public static function fromAi(string $markdown, AiResult $result): self
    {
        return new self(
            markdown: $markdown,
            writer: Document::WRITER_AI,
            model: $result->model,
            aiDriver: $result->driver,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            costUsdEquivalent: $result->costUsdEquivalent,
        );
    }
}
