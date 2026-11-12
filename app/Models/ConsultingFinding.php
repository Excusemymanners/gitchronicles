<?php

namespace App\Models;

use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use Database\Factories\ConsultingFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One problem of a „Consultanță” report: in the code (Semgrep: rule, file, lines) or in a dependency
 * (OSV-Scanner: package, installed and fixed version, advisory). See the migration for every column.
 */
#[Fillable(['tool', 'kind', 'severity', 'original_severity', 'rule_id', 'title', 'message', 'path', 'start_line', 'end_line', 'package', 'ecosystem', 'installed_version', 'fixed_version', 'advisory_id', 'advisory_url'])]
class ConsultingFinding extends Model
{
    /** @use HasFactory<ConsultingFindingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FindingKind::class,
            'severity' => FindingSeverity::class,
            'start_line' => 'integer',
            'end_line' => 'integer',
        ];
    }

    /**
     * The report this problem belongs to.
     *
     * @return BelongsTo<ConsultingReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ConsultingReport::class, 'consulting_report_id');
    }

    /**
     * Whether the problem is in a dependency (it has a package) rather than in the code.
     */
    public function isDependency(): bool
    {
        return $this->package !== null;
    }
}
