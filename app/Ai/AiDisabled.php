<?php

namespace App\Ai;

/**
 * No AI driver is configured (AI_DRIVER=none, or "claude" without an API key).
 *
 * It is not a failure: callers catch it first and switch to their offline mode
 * (the template documentation writer, the chat answer made only of code fragments).
 * It extends AiException, so a caller that only catches AiException is still safe.
 */
class AiDisabled extends AiException
{
    /**
     * Create a new exception with its message for the interface.
     */
    public function __construct()
    {
        parent::__construct('Generarea cu AI este oprită pe acest server.');
    }
}
