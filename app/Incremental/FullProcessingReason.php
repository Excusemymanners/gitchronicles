<?php

namespace App\Incremental;

/**
 * Why an update processed everything instead of only the changes, saved on the run as structured facts
 * (change_summary.full_reason): a reason code plus its real numbers, so the interface can word it in the
 * visitor's language. sentence() gives the English wording (the content language), which the „Istoric”
 * tab shows until the interface words the facts itself.
 *
 * Runs saved before the facts existed hold a sentence (in Romanian) instead; sentence() returns it unchanged.
 */
final class FullProcessingReason
{
    /**
     * The commit processed last time is no longer in the repository's history.
     */
    public const MISSING_COMMIT = 'missing_commit';

    /**
     * More files changed than config('gitchronicle.incremental.full_threshold') allows.
     */
    public const OVER_THRESHOLD = 'over_threshold';

    /**
     * The facts of a run whose last processed commit cannot be found.
     *
     * @return array{code: string}
     */
    public static function missingCommit(): array
    {
        return ['code' => self::MISSING_COMMIT];
    }

    /**
     * The facts of a run in which too many files changed.
     *
     * @return array{code: string, changed_files: int, tracked_files: int, threshold_percent: int}
     */
    public static function overThreshold(int $changedFiles, int $trackedFiles, float $threshold): array
    {
        return [
            'code' => self::OVER_THRESHOLD,
            'changed_files' => $changedFiles,
            'tracked_files' => $trackedFiles,
            'threshold_percent' => (int) round($threshold * 100),
        ];
    }

    /**
     * The saved reason as an English sentence, or null when there is none. A sentence saved by an
     * older run is returned as it is.
     *
     * @param  array<string, mixed>|string|null  $stored
     */
    public static function sentence(array|string|null $stored): ?string
    {
        if (! is_array($stored)) {
            return $stored;
        }

        return match ($stored['code'] ?? null) {
            self::MISSING_COMMIT => 'The commit processed last time is no longer in the repository history, so there is nothing to compare with.',
            self::OVER_THRESHOLD => sprintf(
                '%d of %d files changed (%d%%), above the %d%% threshold.',
                $stored['changed_files'],
                $stored['tracked_files'],
                round($stored['changed_files'] / max(1, $stored['tracked_files']) * 100),
                $stored['threshold_percent'],
            ),
            default => null,
        };
    }
}
