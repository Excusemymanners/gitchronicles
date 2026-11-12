<?php

namespace App\Ai;

/**
 * The TextGenerator used when AI is turned off: it never calls anything and always throws AiDisabled,
 * so every feature takes its offline path.
 */
final class NullTextGenerator implements TextGenerator
{
    /**
     * @throws AiDisabled always
     */
    public function generate(string $system, string $user, ?int $timeoutSeconds = null): AiResult
    {
        throw new AiDisabled;
    }
}
