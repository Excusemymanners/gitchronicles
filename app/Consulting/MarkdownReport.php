<?php

namespace App\Consulting;

use App\Documentation\SourceLinks;
use App\Enums\FindingSeverity;
use App\Enums\ScanToolStatus;
use App\Media\MarpText;
use App\Models\ConsultingFinding;
use App\Models\ConsultingReport;
use Illuminate\Support\Collection;

/**
 * „Descarcă raportul”: a ready „Consultanță” report as one Markdown file, with the same sections as the page.
 * Like everything GitChronicle generates for a repository, the file is in English.
 *
 * Paths, package names, titles and messages come from the repository or from the tools, so each one goes
 * through MarpText: Markdown characters escaped (a message cannot become a link or an image that loads
 * something when the file is opened), paths as `code` when they have no backtick. The links are built here,
 * from the repository's owner and name, the analyzed commit and the advisory ids.
 */
final class MarkdownReport
{
    public function __construct(private RiskRanking $riskRanking) {}

    /**
     * The whole report.
     */
    public function render(ConsultingReport $report): string
    {
        $repository = $report->repository;
        $links = new SourceLinks($repository->owner, $repository->name, (string) $report->source_sha);
        $findings = $report->findings()->orderBy('id')->get();

        return implode("\n", [
            "# Consulting report: {$repository->owner}/{$repository->name}",
            '',
            sprintf('Commit `%s`, analyzed on %s UTC by GitChronicle with Semgrep, OSV-Scanner and scc.', $report->source_sha, $report->finished_at?->format('Y-m-d H:i')),
            '',
            ...$this->tools($report),
            '',
            ...$this->summary($report),
            '',
            ...$this->riskZones($report, $links),
            '',
            ...$this->codeFindings($findings->whereNull('package'), $links),
            '',
            ...$this->dependencies($findings->whereNotNull('package')),
            '',
            ...$this->codeStatistics($report),
        ])."\n";
    }

    /**
     * The file name of the download, e.g. "consulting-laravel-framework-abc1234.md".
     */
    public function fileName(ConsultingReport $report): string
    {
        $repository = $report->repository;

        return 'consulting-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', "{$repository->owner}-{$repository->name}").'-'.substr((string) $report->source_sha, 0, 7).'.md';
    }

    /**
     * One line per tool: its version, duration and findings, or why it did not run.
     *
     * @return list<string>
     */
    private function tools(ConsultingReport $report): array
    {
        $lines = ['## Tools', ''];

        foreach ($report->tools ?? [] as $tool) {
            $lines[] = match (ScanToolStatus::from($tool['status'])) {
                ScanToolStatus::Ran => sprintf('- %s %s: ran in %.1f s, %d findings.', $tool['name'], $tool['version'], $tool['duration_ms'] / 1000, $tool['finding_count']),
                ScanToolStatus::NotInstalled => sprintf('- %s: not installed on the server.', $tool['name']),
                ScanToolStatus::TimedOut => sprintf('- %s: stopped after %d seconds.', $tool['name'], $tool['error_value']),
                ScanToolStatus::Failed => sprintf('- %s: failed (%s %s).', $tool['name'], str_replace('_', ' ', (string) $tool['error']), $tool['error_value']),
            };
        }

        return $lines;
    }

    /**
     * The AI summary (when there is one) and the counts by severity and kind.
     *
     * @return list<string>
     */
    private function summary(ConsultingReport $report): array
    {
        $counts = $report->counts ?? [];
        $lines = ['## Summary', ''];

        if ($report->summary !== null) {
            $lines = [...$lines, MarpText::text($report->summary, SummaryWriter::MAX_CHARACTERS), '', '*Written by AI from the numbers of this report only.*', ''];
        }

        $lines = [...$lines, '| Severity | Findings |', '|---|---|'];

        foreach (FindingSeverity::cases() as $severity) {
            $lines[] = sprintf('| %s | %d |', ucfirst($severity->value), $counts[$severity->value] ?? 0);
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Bugs in the code: %d. Vulnerabilities in the code: %d. Vulnerable dependencies: %d packages (%d advisories).',
            $counts['bugs'] ?? 0,
            $counts['code_vulnerabilities'] ?? 0,
            $counts['vulnerable_packages'] ?? 0,
            $counts['dependency_advisories'] ?? 0,
        );

        if ($report->omitted_count > 0) {
            $lines[] = '';
            $lines[] = sprintf('Left out by the cap of %d findings per tool: %d (the least severe).', config('gitchronicle.consulting.max_findings'), $report->omitted_count);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function riskZones(ConsultingReport $report, SourceLinks $links): array
    {
        $zones = $this->riskRanking->zones($report, $report->repository);
        $lines = ['## Risk zones', '', RiskRanking::FORMULA, ''];

        if ($zones === []) {
            return [...$lines, 'No often-changed file has findings in the code (or the git history was not imported).'];
        }

        $lines = [...$lines, '| File | Commits | Findings | Score |', '|---|---|---|---|'];

        foreach ($zones as $zone) {
            $lines[] = sprintf('| [%s](%s) | %d | %d | %d |', $this->code($zone['path']), $links->file($zone['path']), $zone['commit_count'], $zone['finding_count'], $zone['score']);
        }

        return $lines;
    }

    /**
     * The problems in the code, grouped by file, each with its lines on GitHub.
     *
     * @param  Collection<int, ConsultingFinding>  $findings
     * @return list<string>
     */
    private function codeFindings(Collection $findings, SourceLinks $links): array
    {
        $lines = ['## Findings in the code', ''];

        if ($findings->isEmpty()) {
            return [...$lines, 'Semgrep found no problems in the code.'];
        }

        foreach ($findings->groupBy('path')->sortKeys() as $path => $fileFindings) {
            $lines = [...$lines, '### '.$this->code((string) $path), ''];

            foreach ($fileFindings->sortBy('start_line') as $finding) {
                $lines[] = sprintf(
                    '- **%s** %s: %s ([lines %d–%d](%s)). %s',
                    ucfirst($finding->severity->value),
                    $finding->kind->value,
                    MarpText::text($finding->title),
                    $finding->start_line,
                    $finding->end_line ?? $finding->start_line,
                    $links->lines((string) $path, (int) $finding->start_line, $finding->end_line ?? (int) $finding->start_line),
                    MarpText::text($finding->message, Finding::MESSAGE_LIMIT),
                );
            }

            $lines[] = '';
        }

        return $lines;
    }

    /**
     * The vulnerable dependencies, the most serious first.
     *
     * @param  Collection<int, ConsultingFinding>  $findings
     * @return list<string>
     */
    private function dependencies(Collection $findings): array
    {
        $lines = ['## Vulnerable dependencies', ''];

        if ($findings->isEmpty()) {
            return [...$lines, 'OSV-Scanner found no dependency with a known vulnerability.'];
        }

        $lines = [...$lines, '| Package | Installed | Fixed in | Severity | Advisory |', '|---|---|---|---|---|'];
        $sorted = $findings->sortBy([fn (ConsultingFinding $a, ConsultingFinding $b): int => $a->severity->rank() <=> $b->severity->rank(), ['package', 'asc']]);

        foreach ($sorted as $finding) {
            $lines[] = sprintf(
                '| %s (%s) | %s | %s | %s%s | [%s](%s): %s |',
                $this->code((string) $finding->package),
                MarpText::text($finding->ecosystem),
                $this->code((string) $finding->installed_version),
                $finding->fixed_version === null ? 'unknown' : $this->code($finding->fixed_version),
                ucfirst($finding->severity->value),
                $finding->original_severity === null ? '' : ' ('.MarpText::text($finding->original_severity).')',
                MarpText::text($finding->advisory_id),
                $finding->advisory_url,
                MarpText::text($finding->title, 200),
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function codeStatistics(ConsultingReport $report): array
    {
        $lines = ['## Code statistics', ''];
        $languages = $report->code_statistics['languages'] ?? [];

        if ($languages === []) {
            return [...$lines, 'scc did not run or found no code.'];
        }

        $lines = [...$lines, '| Language | Files | Lines of code | Comments | Blank lines | Complexity |', '|---|---|---|---|---|---|'];

        foreach ($languages as $language) {
            $lines[] = sprintf('| %s | %d | %d | %d | %d | %d |', MarpText::text($language['name']), $language['files'], $language['code'], $language['comments'], $language['blanks'], $language['complexity']);
        }

        return $lines;
    }

    /**
     * A path or a package name as `code`, or escaped text when it holds a backtick.
     */
    private function code(string $value): string
    {
        $code = MarpText::code($value);

        return $code === null ? MarpText::text($value) : "`{$code}`";
    }
}
