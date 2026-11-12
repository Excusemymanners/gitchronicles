<?php

namespace App\Enums;

/**
 * What a problem of the „Consultanță” report is: a bug (code that does not do what it should) or a
 * vulnerability (code or a dependency that someone could attack).
 */
enum FindingKind: string
{
    case Bug = 'bug';
    case Vulnerability = 'vulnerability';

    /**
     * The label shown on the page.
     */
    public function label(): string
    {
        return __('enums.finding_kind.'.$this->value);
    }
}
