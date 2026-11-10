<?php

namespace App\Enums;

/**
 * The stages a repository goes through: added (pending), cloning, cloned or failed.
 *
 * Cloning and Failed are set by the clone job (Faza 3).
 */
enum RepositoryStatus: string
{
    case Pending = 'pending';
    case Cloning = 'cloning';
    case Cloned = 'cloned';
    case Failed = 'failed';

    /**
     * The label shown to the user in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'În așteptare',
            self::Cloning => 'Se clonează',
            self::Cloned => 'Clonat',
            self::Failed => 'Eroare',
        };
    }
}
