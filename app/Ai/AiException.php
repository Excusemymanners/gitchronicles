<?php

namespace App\Ai;

use RuntimeException;
use Throwable;

/**
 * Claude could not write the text.
 *
 * Like RepositoryProcessingException, the message is written for the interface (in the language of the interface, no prompts,
 * no API keys, no server paths); the technical details go to the log where the exception is thrown.
 *
 * "retryable" says whether trying again later can work (a usage limit, an overloaded API, a lost
 * connection); a queued job uses it to decide between waiting for its backoff and failing right away.
 */
class AiException extends RuntimeException
{
    /**
     * The message for every error without a better explanation.
     */
    public const GENERIC_MESSAGE = 'ai.generic';

    /**
     * The message for a used-up subscription or API rate limit.
     */
    public const LIMIT_MESSAGE = 'ai.limit';

    /**
     * The message when Claude took too long and the request was stopped.
     */
    public const TOO_SLOW_MESSAGE = 'ai.too_slow';

    /**
     * The message when the answer was cut off for being too long.
     */
    public const TOO_LONG_MESSAGE = 'ai.too_long';

    /**
     * The message when Claude refused to write the text.
     */
    public const REFUSED_MESSAGE = 'ai.refused';

    /**
     * Create a new exception with its message for the interface.
     */
    public function __construct(
        string $message = self::GENERIC_MESSAGE,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(__($message), previous: $previous);
    }
}
