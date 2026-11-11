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

    /**
     * Whether nobody pressed a button: a push announced by GitHub or the scheduled check.
     * These runs respect a minimum interval per repository (see UpdateRepository), so a burst of
     * pushes cannot start one update (and its AI calls) after another.
     */
    public function isAutomatic(): bool
    {
        return $this === self::Webhook || $this === self::Schedule;
    }
}
