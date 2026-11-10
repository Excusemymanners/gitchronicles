<?php

namespace App\Git;

use App\Models\CommitFile;
use App\Models\Repository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * What the pages show about the imported git history (the commits and commit_files tables,
 * filled by ImportCommits): the summary of the „Istoric” tab, the „Activitate” of the last
 * 12 months, the „Zone fierbinți” (the files changed most often) and the contributors.
 *
 * Everything is counted from the imported commits only (the newest ones, see ImportCommits),
 * and each method returns plain arrays, ready to be sent to the page.
 */
final class HistoryStatistics
{
    /**
     * Folders with code that is not the project's own: libraries and build output.
     */
    private const IGNORED_FOLDERS = ['vendor', 'node_modules', 'dist', 'build'];

    /**
     * Generated files: minified code and source maps.
     */
    private const IGNORED_SUFFIXES = ['.min.js', '.min.css', '.map'];

    /**
     * Lock files: the package managers rewrite them on every dependency change, so they would
     * always top the list without saying anything about the code.
     */
    private const IGNORED_FILES = [
        'composer.lock', 'package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml',
        'bun.lock', 'bun.lockb', 'Cargo.lock', 'Gemfile.lock', 'poetry.lock', 'Pipfile.lock', 'uv.lock',
        'go.sum', 'flake.lock', 'mix.lock', 'pubspec.lock', 'Podfile.lock', 'packages.lock.json',
    ];

    /**
     * The key numbers of the imported history, or null when no commit was imported yet.
     *
     * @return array{commit_count: int, contributor_count: int, first_commit_at: string, last_commit_at: string}|null
     */
    public function summary(Repository $repository): ?array
    {
        $totals = $repository->commits()
            ->toBase()
            ->selectRaw('count(*) as commit_count, count(distinct author_name) as contributor_count')
            ->selectRaw('min(authored_at) as first_commit_at, max(authored_at) as last_commit_at')
            ->first();

        if ((int) $totals->commit_count === 0) {
            return null;
        }

        return [
            'commit_count' => (int) $totals->commit_count,
            'contributor_count' => (int) $totals->contributor_count,
            'first_commit_at' => self::isoDate($totals->first_commit_at),
            'last_commit_at' => self::isoDate($totals->last_commit_at),
        ];
    }

    /**
     * The „Activitate” card: the number of commits in each of the last 12 months (the current
     * month last, months without commits included as 0), plus the summary's numbers.
     * Null when no commit was imported yet.
     *
     * Only the newest commits are imported, so for an older month we may simply not have its commits.
     * The history is complete when its first commit (the one without a parent) was imported; otherwise
     * every month up to the one of the oldest imported commit is unknown (null), never 0, and so is
     * the total of the 12 months.
     *
     * The months are counted in PHP, in the application's time zone: the SQL for "the month of
     * a date" differs between SQLite and PostgreSQL, and a year has at most a few thousand commits.
     *
     * @return array{months: list<array{month: string, commit_count: int|null}>, commits_last_year: int|null, commit_count: int, contributor_count: int, first_commit_at: string, last_commit_at: string, is_complete: bool}|null
     */
    public function activity(Repository $repository): ?array
    {
        $summary = $this->summary($repository);

        if ($summary === null) {
            return null;
        }

        $isComplete = $repository->commits()->whereNull('parent_sha')->exists();
        $oldestImportedMonth = CarbonImmutable::parse($summary['first_commit_at'])->format('Y-m');
        $firstMonth = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        $commitsPerMonth = $repository->commits()
            ->where('authored_at', '>=', $firstMonth)
            ->pluck('authored_at')
            ->countBy(fn (DateTimeInterface $date): string => $date->format('Y-m'));

        $months = [];

        for ($offset = 0; $offset < 12; $offset++) {
            $month = $firstMonth->addMonths($offset)->format('Y-m');
            $isKnown = $isComplete || $month > $oldestImportedMonth;

            $months[] = ['month' => $month, 'commit_count' => $isKnown ? ($commitsPerMonth[$month] ?? 0) : null];
        }

        $counts = array_column($months, 'commit_count');

        return [
            'months' => $months,
            'commits_last_year' => in_array(null, $counts, true) ? null : array_sum($counts),
            'commit_count' => $summary['commit_count'],
            'contributor_count' => $summary['contributor_count'],
            'first_commit_at' => $summary['first_commit_at'],
            'last_commit_at' => $summary['last_commit_at'],
            'is_complete' => $isComplete,
        ];
    }

    /**
     * The „Zone fierbinți”: the files changed by the most commits, most often first (ties: the
     * one changed most recently), without lock files, libraries, build output and generated files
     * (see isIgnored()). For each file: the lines added and removed in total, the date of its last
     * change, how many people changed it, and its current number of lines when the code analysis
     * has the file (null for a deleted or unanalyzed file).
     *
     * The line totals are null unless git counted every change of the file: a file that was
     * binary even once (an icon, for example) would otherwise show a sum that leaves changes out.
     *
     * SQL counts the commits of every path; the ignored paths are dropped here in PHP, where
     * the rules are easy to read, and the first $limit are kept.
     *
     * @return list<array{path: string, commit_count: int, insertions: int|null, deletions: int|null, last_changed_at: string, author_count: int, lines: int|null}>
     */
    public function hotspots(Repository $repository, int $limit): array
    {
        $hotspots = CommitFile::query()
            ->join('commits', 'commits.id', '=', 'commit_files.commit_id')
            ->where('commits.repository_id', $repository->id)
            ->groupBy('commit_files.path')
            ->selectRaw('commit_files.path as path, count(*) as commit_count, count(commit_files.insertions) as counted_changes')
            ->selectRaw('sum(commit_files.insertions) as insertions, sum(commit_files.deletions) as deletions')
            ->selectRaw('max(commits.authored_at) as last_changed_at, count(distinct commits.author_name) as author_count')
            ->orderByDesc('commit_count')
            ->orderByDesc('last_changed_at')
            ->orderBy('path')
            ->toBase()
            ->get()
            ->reject(fn (object $hotspot): bool => self::isIgnored($hotspot->path))
            ->take($limit)
            ->values();

        $lines = $repository->files()->whereIn('path', $hotspots->pluck('path'))->pluck('lines', 'path');

        return $hotspots->map(function (object $hotspot) use ($lines): array {
            $isFullyCounted = (int) $hotspot->counted_changes === (int) $hotspot->commit_count;

            return [
                'path' => $hotspot->path,
                'commit_count' => (int) $hotspot->commit_count,
                'insertions' => $isFullyCounted ? (int) $hotspot->insertions : null,
                'deletions' => $isFullyCounted ? (int) $hotspot->deletions : null,
                'last_changed_at' => self::isoDate($hotspot->last_changed_at),
                'author_count' => (int) $hotspot->author_count,
                'lines' => $lines[$hotspot->path] ?? null,
            ];
        })->all();
    }

    /**
     * The people who wrote the imported commits, the one with the most commits first, with the
     * dates of their first and last commit. A person is an author name (after the project's .mailmap).
     *
     * @return list<array{name: string, commit_count: int, first_commit_at: string, last_commit_at: string}>
     */
    public function contributors(Repository $repository, int $limit): array
    {
        return $repository->commits()
            ->toBase()
            ->selectRaw('author_name, count(*) as commit_count, min(authored_at) as first_commit_at, max(authored_at) as last_commit_at')
            ->groupBy('author_name')
            ->orderByDesc('commit_count')
            ->orderBy('author_name')
            ->limit($limit)
            ->get()
            ->map(fn (object $contributor): array => [
                'name' => $contributor->author_name,
                'commit_count' => (int) $contributor->commit_count,
                'first_commit_at' => self::isoDate($contributor->first_commit_at),
                'last_commit_at' => self::isoDate($contributor->last_commit_at),
            ])
            ->all();
    }

    /**
     * Whether a changed file is left out of the „Zone fierbinți”: inside a library or build folder
     * (at any depth), minified or a source map, or a lock file.
     */
    public static function isIgnored(string $path): bool
    {
        $folders = array_slice(explode('/', $path), 0, -1);

        return array_intersect($folders, self::IGNORED_FOLDERS) !== []
            || Str::endsWith($path, self::IGNORED_SUFFIXES)
            || in_array(basename($path), self::IGNORED_FILES, true);
    }

    /**
     * A date read by an aggregate (min, max): the database returns it as text in UTC.
     */
    private static function isoDate(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->toIso8601String();
    }
}
