<?php

namespace App\Ai;

/**
 * The answer of one TextGenerator call, with what it consumed (saved with the generated text,
 * e.g. documents.input_tokens, and summed in processing_runs for the thesis measurements).
 */
final readonly class AiResult
{
    /**
     * @param  string  $text  the text Claude wrote
     * @param  int  $inputTokens  every input token: uncached + written to the cache + read from the cache
     * @param  int  $outputTokens  the tokens Claude wrote (thinking included)
     * @param  float|null  $costUsdEquivalent  what the call would cost on the API, in dollars, when the driver reports it
     *                                         (the Claude Code CLI does; on a subscription nothing is billed per call)
     * @param  string  $model  the model that answered, e.g. "claude-opus-5-5"
     * @param  string  $driver  the driver that made the call: "claude-code" or "claude"
     */
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $costUsdEquivalent,
        public string $model,
        public string $driver,
    ) {}
}
