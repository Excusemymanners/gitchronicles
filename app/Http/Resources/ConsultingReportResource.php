<?php

namespace App\Http\Resources;

use App\Consulting\RiskRanking;
use App\Documentation\SourceLinks;
use App\Enums\ConsultingStatus;
use App\Enums\FindingSeverity;
use App\Enums\ScanToolStatus;
use App\Models\ConsultingFinding;
use App\Models\ConsultingReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * What the „Consultanță” page shows about the newest report of a repository (the ConsultingReport type in
 * TypeScript): its state, what each tool did and, once it is ready, the results — the counts, the AI summary,
 * the risk zones, the problems in the code grouped by file (with links to the exact lines on GitHub at the
 * analyzed commit), the vulnerable dependencies and the code statistics.
 *
 * While the report is waiting or running, "result" is null: the page polls only this prop and no heavy
 * query runs until the report is ready.
 *
 * @mixin ConsultingReport
 */
class ConsultingReportResource extends JsonResource
{
    /**
     * The order of the tools on the page (the order they run in).
     */
    private const TOOL_ORDER = ['scc', 'osv-scanner', 'semgrep', 'runtime'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_working' => $this->status->isWorking(),
            'error' => $this->error,
            'sha' => $this->source_sha,
            'is_stale' => $this->source_sha !== $this->repository->last_sha,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'age_days' => $this->status === ConsultingStatus::Ready ? (int) $this->finished_at?->diffInDays(now(), absolute: true) : null,
            'stale_after_days' => (int) config('gitchronicle.consulting.stale_after_days'),
            'freshness' => $this->freshness(),
            'tools' => $this->tools(),
            'result' => $this->status === ConsultingStatus::Ready ? $this->result() : null,
        ];
    }

    /**
     * How current the data of each source is: when the report was made, the date and revisions of the Semgrep rules
     * (null when the refresh script never ran), when OSV-Scanner asked the vulnerability database and when the
     * support dates of the runtimes were fetched. A source that did not run has no entry.
     *
     * @return array{rules: array{updated_at: string, revision: string, sources: int}|null, osv_queried_at: string|null, runtime_fetched_at: string|null, has_rules: bool, has_osv: bool, has_runtime: bool}
     */
    private function freshness(): array
    {
        $tools = $this->resource->tools ?? [];
        $ran = fn (string $key): bool => ($tools[$key]['status'] ?? null) === ScanToolStatus::Ran->value;

        return [
            'has_rules' => $ran('semgrep'),
            'rules' => $ran('semgrep') ? ($tools['semgrep']['details']['rules'] ?? null) : null,
            'has_osv' => $ran('osv-scanner'),
            'osv_queried_at' => $ran('osv-scanner') ? ($tools['osv-scanner']['details']['queried_at'] ?? null) : null,
            'has_runtime' => $ran('runtime'),
            'runtime_fetched_at' => $ran('runtime') ? ($tools['runtime']['details']['fetched_at'] ?? null) : null,
        ];
    }

    /**
     * The table of the declared runtime and framework versions, and whether the sources could be read.
     *
     * @return array{rows: list<array<string, mixed>>, error: string|null, error_value: int|null, fetched_at: string|null}|null
     */
    private function runtimeVersions(): ?array
    {
        $tool = $this->resource->tools['runtime'] ?? null;

        if ($tool === null) {
            return null;
        }

        return [
            'rows' => $tool['details']['versions'] ?? [],
            'error' => $tool['error'],
            'error_value' => $tool['error_value'] ?? null,
            'fetched_at' => $tool['details']['fetched_at'] ?? null,
        ];
    }

    /**
     * What each tool did, in the order they run.
     *
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return collect($this->resource->tools ?? [])
            ->sortBy(fn (array $tool, string $key): int|false => array_search($key, self::TOOL_ORDER, true))
            ->map(fn (array $tool, string $key): array => [
                'key' => $key,
                'name' => $tool['name'],
                'status' => $tool['status'],
                'status_label' => ScanToolStatus::from($tool['status'])->label(),
                'version' => $tool['version'],
                'duration_ms' => $tool['duration_ms'],
                'finding_count' => $tool['finding_count'],
                'omitted_count' => $tool['omitted_count'],
                'error' => $tool['error'],
                'error_value' => $tool['error_value'] ?? null,
                'details' => (object) [...collect($tool['details'])->except('versions')->all(), ...(isset($tool['details']['versions']) ? ['versions_declared' => count($tool['details']['versions'])] : [])],
            ])
            ->values()
            ->all();
    }

    /**
     * The results of a ready report.
     *
     * @return array<string, mixed>
     */
    private function result(): array
    {
        $links = new SourceLinks($this->repository->owner, $this->repository->name, (string) $this->source_sha);
        $findings = $this->findings()->orderBy('id')->get();

        return [
            'summary' => $this->summary,
            'counts' => $this->counts,
            'finding_count' => $this->finding_count,
            'omitted_count' => $this->omitted_count,
            'risk' => [
                'weights' => RiskRanking::weights(),
                'candidate_count' => RiskRanking::HOTSPOTS,
                'zones' => array_map(fn (array $zone): array => [
                    ...$zone,
                    'worst_severity_label' => FindingSeverity::from($zone['worst_severity'])->label(),
                    'url' => $links->file($zone['path']),
                ], app(RiskRanking::class)->zones($this->resource, $this->repository)),
            ],
            'runtime' => $this->runtimeVersions(),
            'files' => $this->codeFiles($findings->where('tool', 'semgrep'), $links),
            'dependencies' => $findings->where('tool', 'osv-scanner')
                ->sortBy([fn (ConsultingFinding $a, ConsultingFinding $b): int => $a->severity->rank() <=> $b->severity->rank(), ['package', 'asc']])
                ->map(fn (ConsultingFinding $finding): array => [
                    ...$this->severityOf($finding),
                    'id' => $finding->id,
                    'package' => $finding->package,
                    'ecosystem' => $finding->ecosystem,
                    'installed_version' => $finding->installed_version,
                    'fixed_version' => $finding->fixed_version,
                    'title' => $finding->title,
                    'advisory_id' => $finding->advisory_id,
                    'advisory_url' => $finding->advisory_url,
                    'path' => $finding->path,
                ])
                ->values()
                ->all(),
            'code_statistics' => $this->code_statistics,
            'download_url' => route('repositories.consulting.download', $this->repository, absolute: false),
        ];
    }

    /**
     * The problems in the code grouped by file: the file with the most serious problem first (ties: more
     * problems, then the path), and inside a file by line. Each problem links to its lines on GitHub.
     *
     * @param  Collection<int, ConsultingFinding>  $findings
     * @return list<array<string, mixed>>
     */
    private function codeFiles(Collection $findings, SourceLinks $links): array
    {
        return $findings
            ->groupBy('path')
            ->map(function (Collection $fileFindings, string $path) use ($links): array {
                $sorted = $fileFindings->sortBy([['start_line', 'asc'], ['id', 'asc']])->values();
                $worst = $fileFindings->sortBy(fn (ConsultingFinding $finding): int => $finding->severity->rank())->first();

                return [
                    'path' => $path,
                    'url' => $links->file($path),
                    'worst_rank' => $worst->severity->rank(),
                    'worst_severity' => $worst->severity->value,
                    'findings' => $sorted->map(fn (ConsultingFinding $finding): array => [
                        ...$this->severityOf($finding),
                        'id' => $finding->id,
                        'kind' => $finding->kind->value,
                        'kind_label' => $finding->kind->label(),
                        'rule_id' => $finding->rule_id,
                        'title' => $finding->title,
                        'message' => $finding->message,
                        'start_line' => $finding->start_line,
                        'end_line' => $finding->end_line,
                        'url' => $finding->start_line === null ? $links->file($path) : $links->lines($path, $finding->start_line, $finding->end_line ?? $finding->start_line),
                    ])->all(),
                ];
            })
            ->sortBy([['worst_rank', 'asc'], [fn (array $a, array $b): int => count($b['findings']) <=> count($a['findings'])], ['path', 'asc']])
            ->map(fn (array $file): array => collect($file)->except('worst_rank')->all())
            ->values()
            ->all();
    }

    /**
     * The normalized severity of a problem, its label and what the tool itself said.
     *
     * @return array{severity: string, severity_label: string, original_severity: string|null}
     */
    private function severityOf(ConsultingFinding $finding): array
    {
        return [
            'severity' => $finding->severity->value,
            'severity_label' => $finding->severity->label(),
            'original_severity' => $finding->original_severity,
        ];
    }
}
