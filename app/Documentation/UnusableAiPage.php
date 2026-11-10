<?php

namespace App\Documentation;

use App\Ai\AiException;
use App\Ai\AiResult;

/**
 * Claude answered, but the answer cannot be used as the page (for example a required section is missing).
 * The answer still consumed tokens, so it travels with the exception and the run counts them.
 */
final class UnusableAiPage extends AiException
{
    /**
     * Create the exception with the answer that was rejected.
     */
    public function __construct(public readonly AiResult $result, string $message)
    {
        parent::__construct($message);
    }
}
