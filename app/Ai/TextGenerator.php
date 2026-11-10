<?php

namespace App\Ai;

/**
 * Writes one text with Claude: the documentation pages (Faza 6), the chat answers (Faza 8), ...
 *
 * Callers depend only on this interface; config('gitchronicle.ai_driver') decides which
 * implementation they get (see AppServiceProvider::register()):
 *
 * - ClaudeCodeGenerator: the Claude Code CLI with the subscription of this computer;
 * - ClaudeApiGenerator: the Anthropic API (official PHP SDK);
 * - NullTextGenerator: AI is turned off; it throws AiDisabled, so the caller uses its offline mode.
 */
interface TextGenerator
{
    /**
     * Send one request and return the answer.
     *
     * $system holds the stable instructions (the same for every call of a feature, so the API can cache it);
     * $user holds what changes from call to call (the code, the question).
     *
     * @throws AiDisabled when no AI driver is configured
     * @throws AiException when Claude could not write the text (its message is ready for the interface)
     */
    public function generate(string $system, string $user): AiResult;
}
