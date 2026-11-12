<?php

namespace App\Consulting;

use App\Enums\FindingSeverity;
use App\Git\HistoryStatistics;
use App\Models\ConsultingFinding;
use App\Models\ConsultingReport;
use App\Models\Repository;

/**
 * „Zone de risc”: the files that both change often and have problems. A file that is changed again and
 * again and has a problem in it is where the next bug is most likely to land, and where a fix pays off most.
 *
 * The files that change often are the „Zone fierbinți” of the „Istoric” tab (HistoryStatistics, the same
 * list without lock files, libraries and generated files), counted in the imported commits. They are joined
 * with the problems Semgrep found in the code (the vulnerable dependencies belong to lock files, which are
 * never hotspots). The score is computed when the page asks for it, so it always matches the „Istoric” tab.
 */
final class RiskRanking
{
    /**
     * How many of the most often changed files are candidates.
     */
    public const HOTSPOTS = 50;

    /**
     * How many risk zones the page and the downloaded report show.
     */
    public const SHOWN = 10;

    /**
     * The formula in one sentence, for the downloaded report (English, like everything generated);
     * the page words the same formula from weights() and HOTSPOTS.
     */
    public const FORMULA = 'Risk score of a file = the number of commits that changed it × the severity of its problems (critical 8, high 4, medium 2, low 1, info 0); only the 50 most often changed files with at least one problem in the code are ranked.';

    public function __construct(private HistoryStatistics $statistics) {}

    /**
     * The weight of each severity in the score, e.g. ['critical' => 8, …, 'info' => 0].
     *
     * @return array<string, int>
     */
    public static function weights(): array
    {
        return collect(FindingSeverity::cases())->mapWithKeys(fn (FindingSeverity $severity): array => [$severity->value => $severity->weight()])->all();
    }

    /**
     * The risk zones of a report, the highest score first (ties: more commits, then the path).
     *
     * @return list<array{path: string, commit_count: int, finding_count: int, severity_points: int, score: int, worst_severity: string}>
     */
    public function zones(ConsultingReport $report, Repository $repository, int $limit = self::SHOWN): array
    {
        $hotspots = collect($this->statistics->hotspots($repository, self::HOTSPOTS))->keyBy('path');

        if ($hotspots->isEmpty()) {
            return [];
        }

        return $report->findings()
            ->where('tool', 'semgrep')
            ->whereIn('path', $hotspots->keys())
            ->get(['path', 'severity'])
            ->groupBy('path')
            ->map(function ($findings, string $path) use ($hotspots): array {
                $points = (int) $findings->sum(fn (ConsultingFinding $finding): int => $finding->severity->weight());
                $commitCount = (int) $hotspots[$path]['commit_count'];

                return [
                    'path' => $path,
                    'commit_count' => $commitCount,
                    'finding_count' => $findings->count(),
                    'severity_points' => $points,
                    'score' => $commitCount * $points,
                    'worst_severity' => $findings->sortBy(fn (ConsultingFinding $finding): int => $finding->severity->rank())->first()->severity->value,
                ];
            })
            ->filter(fn (array $zone): bool => $zone['score'] > 0)
            ->sortBy([['score', 'desc'], ['commit_count', 'desc'], ['path', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }
}
