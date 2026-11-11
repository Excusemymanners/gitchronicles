<?php

namespace App\Models;

use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
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
 *
 * change_summary (Faza 9) is what an update found between from_sha and to_sha: the number of files
 * added, modified and deleted, the affected modules, the modules that appeared or disappeared, the
 * changed files of the root folder and, for a Full run, why it was not incremental. The column is not
 * called "changes" because Eloquent keeps its own list of changed attributes under that name.
 */
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([
    'mode', 'trigger', 'from_sha', 'to_sha', 'status',
    'files_total', 'files_changed', 'files_analyzed', 'modules_total', 'modules_affected',
    'change_summary', 'module_paths', 'documents_regenerated', 'documents_kept', 'context_characters',
    'input_tokens', 'output_tokens', 'cost_usd_equivalent',
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
            'change_summary' => 'array',
            'module_paths' => 'array',
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
     * Whether this run processes only what changed since from_sha (Faza 9). The first processing
     * (Initial) and a full reprocessing (Full) both look at every file and every page.
     */
    public function isIncremental(): bool
    {
        return $this->mode === ProcessingMode::Incremental;
    }

    /**
     * The status the repository shows while a step of this run works: the step's own status during
     * the first processing („Se clonează”, „Se analizează codul”, ...), and „Se actualizează” for
     * every step of an update, because the repository already has its documentation on the page.
     */
    public function statusFor(RepositoryStatus $step): RepositoryStatus
    {
        return $this->mode === ProcessingMode::Initial ? $step : RepositoryStatus::Updating;
    }

    /**
     * The modules this run found affected by the changes (see ImpactAnalysis), by their path.
     *
     * @return array<string, array{path: string, files: int, via: string}>
     */
    public function affectedModules(): array
    {
        return array_column($this->change_summary['modules'] ?? [], null, 'path');
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
