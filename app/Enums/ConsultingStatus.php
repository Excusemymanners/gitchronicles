<?php

namespace App\Enums;

/**
 * The state of a „Consultanță” report: waiting in the queue (Pending), the tools are reading the clone
 * (Running), done (Ready) or stopped by an error (Failed, with the reason in consulting_reports.error).
 */
enum ConsultingStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * The label shown on the page.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'În așteptare',
            self::Running => 'Se analizează',
            self::Ready => 'Gata',
            self::Failed => 'Eroare',
        };
    }

    /**
     * Whether the analysis still has work to do: while this is true, the page asks again every few seconds.
     */
    public function isWorking(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}
