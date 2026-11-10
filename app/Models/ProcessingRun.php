<?php

namespace App\Models;

use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use Database\Factories\ProcessingRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution of the processing pipeline for a repository: the measurements of the thesis.
 *
 * ProcessRepository creates it as "running", each pipeline step fills its own counters
 * (for example CloneRepository sets to_sha and files_total), and finish() closes it.
 *
 * Its times are saved with milliseconds (DateFormat), so duration_ms is exact.
 */
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([
    'mode', 'trigger', 'from_sha', 'to_sha', 'status',
    'files_total', 'files_changed', 'files_analyzed', 'modules_total', 'modules_affected',
    'documents_regenerated', 'input_tokens', 'output_tokens', 'cost_usd_equivalent',
    'duration_ms', 'started_at', 'finished_at', 'error',
])]
class ProcessingRun extends Model
{
    /** @use HasFactory<ProcessingRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ProcessingMode::class,
            'trigger' => ProcessingTrigger::class,
            'status' => ProcessingRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cost_usd_equivalent' => 'decimal:6',
        ];
    }

    /**
     * The repository this run processed.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Close the run: its final status, when it ended, how long it took and,
     * for a failure, the message that was shown to the user.
     */
    public function finish(ProcessingRunStatus $status, ?string $error = null): void
    {
        $finishedAt = now();

        $this->update([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $this->started_at->diffInMilliseconds($finishedAt),
            'error' => $error,
        ]);
    }
}
