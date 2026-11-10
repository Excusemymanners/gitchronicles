<?php

namespace App\Documentation;

/**
 * What happened to one page in a documentation run: written (by Claude or by the template) or left
 * as it was because its input did not change, and what the AI calls consumed (failed answers included).
 */
final readonly class PageResult
{
    /**
     * @param  string|null  $writer  "ai" or "template" when the page was written, null when it was unchanged
     * @param  string|null  $aiError  why Claude could not write the page (the template wrote it instead)
     */
    public function __construct(
        public string $slug,
        public bool $written,
        public ?string $writer = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $costUsdEquivalent = null,
        public ?string $aiError = null,
    ) {}

    /**
     * A page whose input did not change, so nothing was written.
     */
    public static function unchanged(string $slug): self
    {
        return new self($slug, written: false);
    }
}
