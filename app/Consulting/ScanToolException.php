<?php

namespace App\Consulting;

use RuntimeException;

/**
 * A tool of the „Consultanță” report could not give a usable answer. The report stores the reason as a code
 * with its number (the page words it in the visitor's language), never as a sentence:
 *
 * - "timed_out": it ran longer than its timeout (value = the seconds);
 * - "output_too_large": it wrote more than max_output_bytes (value = the megabytes);
 * - "unreadable_output": its answer was not the JSON it writes after a run (value = the exit code);
 * - "exit_code": it stopped with an exit code that means an error (value = the exit code);
 * - "source_unavailable": the version check could not read any public source of support dates (value = the HTTP status, 0 = no answer).
 *
 * The exception's message is English, for the log only.
 */
final class ScanToolException extends RuntimeException
{
    public const TIMED_OUT = 'timed_out';

    public const OUTPUT_TOO_LARGE = 'output_too_large';

    public const UNREADABLE_OUTPUT = 'unreadable_output';

    public const EXIT_CODE = 'exit_code';

    public const SOURCE_UNAVAILABLE = 'source_unavailable';

    /**
     * Create a new exception with its reason code and the number that goes with it.
     */
    public function __construct(public readonly string $reason, public readonly ?int $value = null)
    {
        parent::__construct("Consulting tool failed: {$reason}".($value === null ? '' : " ({$value})"));
    }
}
