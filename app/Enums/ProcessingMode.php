<?php

namespace App\Enums;

/**
 * How much work a processing run does: the first processing of a repository (Initial),
 * only what changed since the last processed commit (Incremental), or everything again
 * because too much changed (Full). The last two arrive with the incremental update (Faza 9).
 */
enum ProcessingMode: string
{
    case Initial = 'initial';
    case Incremental = 'incremental';
    case Full = 'full';
}
