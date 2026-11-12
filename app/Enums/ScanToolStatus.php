<?php

namespace App\Enums;

/**
 * What happened to one tool of a „Consultanță” report: it ran, it is not installed on the server, it
 * stopped with an error (or its output was unreadable or too big) or it ran out of time.
 */
enum ScanToolStatus: string
{
    case Ran = 'ran';
    case NotInstalled = 'not_installed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';

    /**
     * The label shown on the page.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ran => 'A rulat',
            self::NotInstalled => 'Nu e instalat',
            self::Failed => 'Eroare',
            self::TimedOut => 'Timp depășit',
        };
    }
}
