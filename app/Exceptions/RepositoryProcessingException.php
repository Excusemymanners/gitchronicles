<?php

namespace App\Exceptions;

use App\Support\StoredMessage;
use RuntimeException;
use Throwable;

/**
 * A pipeline step failed for a reason we can explain to the user.
 *
 * Its message is a StoredMessage (a translation key and its values, without git output, server paths or tokens),
 * so it can be saved in repositories.error and shown in the visitor's language. The technical details go to the log, never here.
 */
class RepositoryProcessingException extends RuntimeException
{
    /**
     * The message for every other error (a bug, a database problem, a job killed after its timeout).
     */
    public const UNEXPECTED_ERROR = 'repositories.errors.unexpected';

    /**
     * An exception whose message is the translation key (under repositories.errors) and its values.
     *
     * @param  array<string, string|int|float>  $params
     */
    public static function because(string $key, array $params = []): self
    {
        return new self(StoredMessage::make('repositories.errors.'.$key, $params));
    }

    /**
     * The message to show for the exception that stopped the pipeline: our own message,
     * or the general one for any other exception, whose details stay in the log.
     */
    public static function messageFor(Throwable $exception): string
    {
        return $exception instanceof self ? $exception->getMessage() : StoredMessage::make(self::UNEXPECTED_ERROR);
    }
}
