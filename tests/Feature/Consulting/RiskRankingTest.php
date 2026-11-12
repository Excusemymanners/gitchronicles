<?php

namespace Tests\Feature\Consulting;

use App\Consulting\RiskRanking;
use App\Enums\FindingSeverity;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\ConsultingFinding;
use App\Models\ConsultingReport;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Zone de risc”: the files that change often (the „Zone fierbinți” of the git history) joined with the problems
 * found in their code, ranked by commits × severity points.
 */
class RiskRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_are_ranked_by_commits_times_severity_points(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->changeFile($repository, 'app/Http/ReportController.php', times: 6);
        $this->changeFile($repository, 'app/Models/Report.php', times: 10);
        $this->changeFile($repository, 'resources/js/report.ts', times: 2);
        $this->changeFile($repository, 'composer.lock', times: 12);
        $report = ConsultingReport::factory()->for($repository)->ready()->create();

        // ReportController: 6 commits × (high 4 + medium 2) = 36.
        $this->finding($report, 'app/Http/ReportController.php', FindingSeverity::High);
        $this->finding($report, 'app/Http/ReportController.php', FindingSeverity::Medium);
        // Report: 10 commits × low 1 = 10.
        $this->finding($report, 'app/Models/Report.php', FindingSeverity::Low);
        // report.ts: 2 commits × critical 8 = 16.
        $this->finding($report, 'resources/js/report.ts', FindingSeverity::Critical);
        // A file never changed in the imported history, an informative note only and a dependency: not ranked.
        $this->finding($report, 'tools/convert.py', FindingSeverity::Critical);
        $this->changeFile($repository, 'app/Notes.php', times: 20);
        $this->finding($report, 'app/Notes.php', FindingSeverity::Info);
        ConsultingFinding::factory()->for($report, 'report')->dependency()->create(['path' => 'composer.lock']);

        $zones = app(RiskRanking::class)->zones($report, $repository);

        $this->assertSame(['app/Http/ReportController.php', 'resources/js/report.ts', 'app/Models/Report.php'], array_column($zones, 'path'));
        $this->assertSame([
            'path' => 'app/Http/ReportController.php',
            'commit_count' => 6,
            'finding_count' => 2,
            'severity_points' => 6,
            'score' => 36,
            'worst_severity' => 'high',
        ], $zones[0]);
        $this->assertSame([16, 10], [$zones[1]['score'], $zones[2]['score']]);
    }

    public function test_without_imported_history_there_are_no_risk_zones(): void
    {
        $report = ConsultingReport::factory()->ready()->create();
        $this->finding($report, 'app/Http/ReportController.php', FindingSeverity::Critical);

        $this->assertSame([], app(RiskRanking::class)->zones($report, $report->repository));
    }

    private function changeFile(Repository $repository, string $path, int $times): void
    {
        Commit::factory()->for($repository)->count($times)->create()->each(
            fn (Commit $commit) => CommitFile::factory()->for($commit)->create(['path' => $path]),
        );
    }

    private function finding(ConsultingReport $report, string $path, FindingSeverity $severity): void
    {
        ConsultingFinding::factory()->for($report, 'report')->create(['path' => $path, 'severity' => $severity]);
    }
}
