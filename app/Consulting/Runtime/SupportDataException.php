<?php

namespace App\Consulting\Runtime;

use RuntimeException;

/**
 * A public source of support dates could not be read (no network, an error answer, an answer that is not
 * the expected JSON, or an unknown product). The version check reports it as "could not be checked".
 */
final class SupportDataException extends RuntimeException
{
    public const UNREACHABLE = 'unreachable';

    public const HTTP_ERROR = 'http_error';

    public const UNREADABLE = 'unreadable';

    /**
     * @param  int  $status  the HTTP status of an error answer (404 = the source does not know the product), 0 otherwise
     */
    public function __construct(public readonly string $reason, public readonly int $status = 0)
    {
        parent::__construct("Support data unavailable: {$reason}".($status > 0 ? " (HTTP {$status})" : ''));
    }
}
