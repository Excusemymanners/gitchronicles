<?php

namespace App\Enums;

/**
 * The state of one processing run: still Running, finished well (Succeeded), stopped by an error
 * (Failed), or not needed because nothing changed since the last run (Skipped, Faza 9).
 */
enum ProcessingRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
