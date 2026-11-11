<?php

namespace App\Documentation;

/**
 * What happened to one page in a documentation run: written (by Claude or by the template), left
 * as it was because its input did not change, or kept although its input changed because Claude
 * could not write it again (outdated). It also carries what the AI calls consumed (failed answers included).
 */
final readonly class PageResult
{
    /**
     * @param  string|null  $writer  "ai" or "template" when the page was written, null when it was not
     * @param  string|null  $aiError  why Claude could not write the page
     * @param  bool  $outdated  the page's code changed, but the page written by Claude was kept and marked stale
     * @param  int  $contextCharacters  the size of the text the writer received (or would have received) for a written page
     */
    public function __construct(
        public string $slug,
        public bool $written,
        public ?string $writer = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $costUsdEquivalent = null,
        public ?string $aiError = null,
        public bool $outdated = false,
        public int $contextCharacters = 0,
    ) {}

    /**
     * A page whose input did not change, so nothing was written.
     */
    public static function unchanged(string $slug): self
    {
        return new self($slug, written: false);
    }
}
