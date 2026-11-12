<?php

namespace App\Enums;

/**
 * The state of a video or of the slides: waiting in the queue (Pending), being made (Running),
 * done (Ready) or stopped by an error (Failed, with the reason in media_assets.error).
 */
enum MediaStatus: string
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
        return __('enums.media_status.'.$this->value);
    }

    /**
     * Whether the job still has work to do: while this is true, the page asks again every few seconds.
     */
    public function isWorking(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}
