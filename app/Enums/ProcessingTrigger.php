<?php

namespace App\Enums;

/**
 * What started a processing run: adding the repository (Add), a person asking for it
 * (Manual: the „Reîncearcă” button or the repositories:process command), a GitHub push (Webhook)
 * or a scheduled check (Schedule). The last two arrive with the incremental update (Faza 9).
 */
enum ProcessingTrigger: string
{
    case Add = 'add';
    case Manual = 'manual';
    case Webhook = 'webhook';
    case Schedule = 'schedule';
}
