<?php

namespace App\Consulting;

use App\Enums\ConsultingStatus;
use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use App\Enums\ScanToolStatus;
use App\Models\ConsultingReport;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Makes the „Consultanță” report of a repository from its clone:
 *
 * 1. runs the three tools one after the other — scc (code size), OSV-Scanner (vulnerable dependencies),
 *    Semgrep (problems in the code) — each one on its own, so a missing or failing tool never stops the others;
 * 2. saves, in one transaction, the findings (replacing the ones of an earlier run of the same commit)
 *    and the report: what each tool did, the code statistics and the counts;
 * 3. asks AI for the short executive summary in English, from the report's numbers only (SummaryWriter; skipped
 *    when AI is off or its answer is rejected);
 * 4. keeps only the newest KEPT_REPORTS reports of the repository.
 *
 * The report fails only when no tool could run at all (the reason of each one stays in "tools").
 */
final class ConsultingScanner
{
    /**
     * How many reports of a repository are kept (one per analyzed commit, the newest).
     */
    public const KEPT_REPORTS = 3;

    public function __construct(
        private Scc $scc,
        private OsvScanner $osvScanner,
        private Semgrep $semgrep,
        private SummaryWriter $summaryWriter,
    ) {}

    /**
     * The tools, in the order they run (the fastest first).
     *
     * @return list<ScanTool>
     */
    public function tools(): array
    {
        return [$this->scc, $this->osvScanner, $this->semgrep];
    }

    /**
     * Analyze the clone of the report's repository and fill the report.
     */
    public function scan(ConsultingReport $report): void
    {
        $repository = $report->repository;

        $report->fill(['status' => ConsultingStatus::Running, 'error' => null, 'started_at' => now(), 'finished_at' => null])->save();

        if (! is_dir($repository->clonePath())) {
            $report->markFailed(ConsultingReport::ERROR_CLONE_MISSING);

            return;
        }

        $runs = collect($this->tools())->map(fn (ScanTool $tool): ToolRun => $tool->run($repository->clonePath()));
        $tools = $runs->mapWithKeys(fn (ToolRun $run): array => [$run->key => $run->toArray()])->all();

        if ($runs->doesntContain(fn (ToolRun $run): bool => $run->status === ScanToolStatus::Ran)) {
            $report->update(['tools' => $tools]);
            $report->markFailed($runs->every(fn (ToolRun $run): bool => $run->status === ScanToolStatus::NotInstalled)
                ? ConsultingReport::ERROR_NOT_INSTALLED
                : ConsultingReport::ERROR_NO_TOOL_RAN);

            return;
        }

        $findings = $runs->flatMap(fn (ToolRun $run): array => $run->findings);

        DB::transaction(function () use ($report, $runs, $tools, $findings): void {
            $report->findings()->delete();
            $report->findings()->createMany($findings->map(fn (Finding $finding): array => $finding->toAttributes())->all());

            $report->update([
                'status' => ConsultingStatus::Ready,
                'tools' => $tools,
                'code_statistics' => $runs->firstWhere('key', 'scc')?->details ?: null,
                'counts' => $this->counts($findings),
                'finding_count' => $findings->count(),
                'omitted_count' => $runs->sum(fn (ToolRun $run): int => $run->omittedCount),
                'summary' => null,
                'summary_meta' => null,
                'finished_at' => now(),
            ]);
        });

        $summary = $this->summaryWriter->write($report);

        if ($summary !== null) {
            $report->update(['summary' => $summary['text'], 'summary_meta' => $summary['meta']]);
        }

        $this->pruneOlderReports($repository);
    }

    /**
     * The numbers at the top of the page: the findings by severity, then by kind and place.
     *
     * @param  Collection<int, Finding>  $findings
     * @return array<string, int>
     */
    private function counts(Collection $findings): array
    {
        $bySeverity = [];

        foreach (FindingSeverity::cases() as $severity) {
            $bySeverity[$severity->value] = $findings->filter(fn (Finding $finding): bool => $finding->severity === $severity)->count();
        }

        $inCode = $findings->whereNull('package');
        $inDependencies = $findings->whereNotNull('package');

        return [
            ...$bySeverity,
            'bugs' => $inCode->filter(fn (Finding $finding): bool => $finding->kind === FindingKind::Bug)->count(),
            'code_vulnerabilities' => $inCode->filter(fn (Finding $finding): bool => $finding->kind === FindingKind::Vulnerability)->count(),
            'dependency_advisories' => $inDependencies->count(),
            'vulnerable_packages' => $inDependencies->unique(fn (Finding $finding): string => "{$finding->ecosystem}|{$finding->package}|{$finding->installedVersion}")->count(),
        ];
    }

    /**
     * Delete the reports (and their findings) older than the newest KEPT_REPORTS of the repository.
     */
    private function pruneOlderReports(Repository $repository): void
    {
        $kept = $repository->consultingReports()->latest('id')->limit(self::KEPT_REPORTS)->pluck('id');

        $repository->consultingReports()->whereNotIn('id', $kept)->delete();
    }
}
