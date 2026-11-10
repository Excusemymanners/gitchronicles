<?php

namespace App\Ai;

use RuntimeException;
use Throwable;

/**
 * Claude could not write the text.
 *
 * Like RepositoryProcessingException, the message is written for the interface (Romanian, no prompts,
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
    public const GENERIC_MESSAGE = 'Claude nu a putut scrie textul acum. Încearcă din nou mai târziu.';

    /**
     * The message for a used-up subscription or API rate limit.
     */
    public const LIMIT_MESSAGE = 'Limita abonamentului Claude a fost atinsă; încearcă mai târziu.';

    /**
     * Create a new exception with its message for the interface.
     */
    public function __construct(
        string $message = self::GENERIC_MESSAGE,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
