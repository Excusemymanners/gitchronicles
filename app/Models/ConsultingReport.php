<?php

namespace App\Models;

use App\Enums\ConsultingStatus;
use Database\Factories\ConsultingReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * The „Consultanță” report of a repository at one commit (bonus): the state of the analysis, what each
 * tool did (tools), the code statistics of scc, the counts of the findings and the AI summary.
 * The findings themselves are rows of consulting_findings.
 *
 * repository_id is not fillable: a report is made through the relationship ($repository->consultingReports()).
 */
#[Fillable(['source_sha', 'status', 'error', 'tools', 'code_statistics', 'counts', 'finding_count', 'omitted_count', 'summary', 'summary_meta', 'started_at', 'finished_at'])]
class ConsultingReport extends Model
{
    /** @use HasFactory<ConsultingReportFactory> */
    use HasFactory;

    /**
     * Why a report failed, stored in "error" as a code (the page words it in the visitor's language):
     * the clone is gone, none of the tools is installed, every installed tool failed (each one's reason is
     * in "tools"), or an unexpected error stopped the job (the details go to the log).
     */
    public const ERROR_CLONE_MISSING = 'clone_missing';

    public const ERROR_NOT_INSTALLED = 'not_installed';

    public const ERROR_NO_TOOL_RAN = 'no_tool_ran';

    public const ERROR_UNEXPECTED = 'unexpected';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConsultingStatus::class,
            'tools' => 'array',
            'code_statistics' => 'array',
            'counts' => 'array',
            'summary_meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The analyzed repository.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The problems found by the tools.
     *
     * @return HasMany<ConsultingFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ConsultingFinding::class);
    }

    /**
     * Mark the report as failed, with one of the ERROR_* codes. A failure must never break anything else,
     * so a database error here is only reported.
     */
    public function markFailed(string $errorCode): void
    {
        try {
            $this->update(['status' => ConsultingStatus::Failed, 'error' => $errorCode, 'finished_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
