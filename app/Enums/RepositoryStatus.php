<?php

namespace App\Enums;

/**
 * The stages a repository goes through in the processing pipeline:
 * pending → cloning → analyzing → documenting → ready, or failed at any step.
 * A ready repository becomes "updating" while it is brought up to date (Faza 9).
 *
 * Each pipeline job sets the status of its own step (CloneRepository sets Cloning, ...).
 */
enum RepositoryStatus: string
{
    case Pending = 'pending';
    case Cloning = 'cloning';
    case Analyzing = 'analyzing';
    case Documenting = 'documenting';
    case Ready = 'ready';
    case Updating = 'updating';
    case Failed = 'failed';

    /**
     * The label shown to the user in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'În așteptare',
            self::Cloning => 'Se clonează',
            self::Analyzing => 'Se analizează codul',
            self::Documenting => 'Se scrie documentația',
            self::Ready => 'Gata',
            self::Updating => 'Se actualizează',
            self::Failed => 'Eroare',
        };
    }

    /**
     * Whether the pipeline still has work to do: the repository waits in the queue or a step is running.
     * While this is true, the pages refresh themselves every few seconds.
     */
    public function isProcessing(): bool
    {
        return match ($this) {
            self::Pending, self::Cloning, self::Analyzing, self::Documenting, self::Updating => true,
            self::Ready, self::Failed => false,
        };
    }
}
