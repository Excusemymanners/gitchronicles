<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A pipeline step failed for a reason we can explain to the user.
 *
 * Its message is written for the interface (Romanian, without git output, server paths or tokens),
 * so it can be saved in repositories.error. The technical details go to the log, never here.
 */
class RepositoryProcessingException extends RuntimeException
{
    /**
     * The message for every other error (a bug, a database problem, a job killed after its timeout).
     */
    public const UNEXPECTED_ERROR = 'A apărut o eroare neașteptată în timpul procesării. Încearcă din nou.';

    /**
     * The message to show for the exception that stopped the pipeline: our own message,
     * or the general one for any other exception, whose details stay in the log.
     */
    public static function messageFor(Throwable $exception): string
    {
        return $exception instanceof self ? $exception->getMessage() : self::UNEXPECTED_ERROR;
    }
}
