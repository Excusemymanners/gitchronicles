<?php

namespace App\Consulting\Runtime;

use App\Consulting\Finding;
use App\Consulting\ScanToolException;
use App\Consulting\ToolRun;
use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use App\Enums\ScanToolStatus;
use App\Models\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The "Runtime and framework versions" part of the „Consultanță” report: the PHP, Node.js, Python, Go, Ruby,
 * Java, .NET and framework versions a repository declares (DeclarationReader), compared with the live
 * support data of public sources (SupportData: endoflife.date, Node.js's index, php.net).
 *
 * It looks like a tool of the report (key "runtime", a ToolRun) but runs no program. Each declared version gets a status:
 *
 * - end_of_life: its release line gets no security fixes any more → a high finding;
 * - behind: the line is supported but the declared version is an exact one older than the newest release of
 *   its line → a medium finding for a runtime (with the number of security releases missed, when the source
 *   flags them), a low one for a framework;
 * - security_only: only security fixes are made any more → a low finding;
 * - current: no finding, the row says until when it is supported;
 * - unknown: the source has no such release line; unchecked: the source could not be read (the row says so).
 *
 * A range ("^8.2", ">=18") is judged by the lowest version it allows, and every finding says so.
 * When no source can be read at all, the tool is "failed" with the reason, and the other tools run on.
 * Texts of the findings are English (like the rest of the generated report).
 */
final class RuntimeVersionCheck
{
    public const KEY = 'runtime';

    public function __construct(private DeclarationReader $reader, private SupportData $data) {}

    /**
     * Check the versions declared in the repository's clone.
     */
    public function run(Repository $repository): ToolRun
    {
        $startedAt = hrtime(true);
        $declarations = $this->reader->read($repository);

        /** @var array<string, array<string, mixed>> $lifecycles */
        $lifecycles = [];
        /** @var array<string, SupportDataException> $failures */
        $failures = [];

        foreach (array_unique(array_map(fn (Declaration $declaration): string => $declaration->product, $declarations)) as $product) {
            try {
                $lifecycles[$product] = $this->data->lifecycle(Products::all()[$product]['slug']);
            } catch (SupportDataException $exception) {
                $failures[$product] = $exception;
            } catch (Throwable $exception) {
                report($exception);
                $failures[$product] = new SupportDataException(SupportDataException::UNREACHABLE);
            }
        }

        $rows = [];
        $findings = [];
        $places = [];

        foreach ($declarations as $declaration) {
            if (! isset($lifecycles[$declaration->product])) {
                $rows[] = $this->row($declaration, 'unchecked', reason: $failures[$declaration->product]->reason);

                continue;
            }

            [$row, $finding] = $this->evaluate($declaration, $lifecycles[$declaration->product]);
            $rows[] = $row;

            if ($finding === null) {
                continue;
            }

            // The same problem declared in several files is one finding that lists the other places.
            $key = "{$row['product']}|{$row['version']}|{$row['status']}";

            if (isset($findings[$key])) {
                $places[$key][] = $declaration->line === null ? $declaration->path : "{$declaration->path}:{$declaration->line}";
            } else {
                $findings[$key] = $finding;
            }
        }

        $findings = array_values(array_map(fn (Finding $finding, string $key): Finding => isset($places[$key]) ? $this->withPlaces($finding, $places[$key]) : $finding, $findings, array_keys($findings)));

        $details = [
            'versions' => $rows,
            'fetched_at' => collect($lifecycles)->pluck('fetched_at')->sort()->first(),
            'sources' => $declarations === [] ? [] : ['endoflife.date', 'nodejs.org', 'php.net'],
        ];

        if ($declarations !== [] && $lifecycles === []) {
            $first = reset($failures);

            return new ToolRun(self::KEY, 'Runtime versions', ScanToolStatus::Failed, durationMs: self::millisecondsSince($startedAt), error: ScanToolException::SOURCE_UNAVAILABLE, errorValue: $first->status, details: $details);
        }

        return new ToolRun(self::KEY, 'Runtime versions', ScanToolStatus::Ran, durationMs: self::millisecondsSince($startedAt), findings: $findings, details: $details);
    }

    /**
     * The row of the table and, when the status is a problem, its finding.
     *
     * @param  array<string, mixed>  $lifecycle
     * @return array{0: array<string, mixed>, 1: Finding|null}
     */
    private function evaluate(Declaration $declaration, array $lifecycle): array
    {
        $releases = $lifecycle['releases'];
        $release = $this->releaseOf($declaration->version, $releases);

        if ($release === null) {
            return [$this->row($declaration, 'unknown', url: $lifecycle['url']), null];
        }

        $isExact = ! $declaration->isRange && substr_count($declaration->version, '.') >= 2;
        $newest = $release['latest'];
        $isBehind = $isExact && $newest !== null && version_compare($declaration->version, $newest, '<');
        $missingSecurity = $isBehind ? $this->missingSecurityReleases($declaration, $release['name']) : null;
        $target = $this->recommendedLine($declaration, $releases);

        $status = match (true) {
            $release['is_eol'] => 'end_of_life',
            $isBehind => 'behind',
            $release['is_eoas'] => 'security_only',
            default => 'current',
        };

        $row = $this->row($declaration, $status, [
            'release_line' => $release['name'],
            'supported_until' => $release['eol_from'],
            'newest' => $newest,
            'target' => $status === 'behind' ? $release['name'] : $target['name'] ?? null,
            'target_newest' => $status === 'behind' ? $newest : $target['latest'] ?? null,
            'missing_security_releases' => $missingSecurity,
            'url' => $lifecycle['url'],
        ]);

        return [$row, $this->finding($declaration, $row, $lifecycle['label'])];
    }

    /**
     * A finding for the statuses that are a problem.
     *
     * @param  array<string, mixed>  $row
     */
    private function finding(Declaration $declaration, array $row, string $label): ?Finding
    {
        $severity = match ($row['status']) {
            'end_of_life' => FindingSeverity::High,
            'behind' => Products::all()[$declaration->product]['kind'] === 'runtime' ? FindingSeverity::Medium : FindingSeverity::Low,
            'security_only' => FindingSeverity::Low,
            default => null,
        };

        if ($severity === null) {
            return null;
        }

        $line = $row['release_line'];
        $replacements = [
            'product' => $label,
            'line' => $line,
            'version' => $declaration->version,
            'since' => $this->date($row['supported_until']),
            'until' => $this->date($row['supported_until']),
            'newest' => (string) $row['newest'],
            'target' => (string) ($row['target'] ?? ''),
            'target_newest' => (string) ($row['target_newest'] ?? ''),
            'count' => (string) ($row['missing_security_releases'] ?? 0),
            'where' => $declaration->line === null ? $declaration->path : "{$declaration->path} line {$declaration->line}",
            'declared' => $declaration->declared,
        ];

        $key = match ($row['status']) {
            'end_of_life' => 'end_of_life',
            'behind' => ($row['missing_security_releases'] ?? 0) > 0 ? 'behind_security' : 'behind',
            default => 'security_only',
        };
        $count = (int) ($row['missing_security_releases'] ?? 0);
        $text = fn (string $part): string => $key === 'behind_security'
            ? trans_choice("consulting.runtime.{$key}.{$part}", $count, $replacements, 'en')
            : trans("consulting.runtime.{$key}.{$part}", $replacements, 'en');
        $hasTarget = $row['target'] !== null;
        $isRangeNote = $declaration->isRange ? ' '.trans('consulting.runtime.range_note', $replacements, 'en') : '';
        $message = $text('message')
            .' '.trans('consulting.runtime.declared_in', $replacements, 'en').$isRangeNote
            .' '.($hasTarget ? trans("consulting.runtime.{$key}.change", $replacements, 'en') : '');

        return new Finding(
            tool: self::KEY,
            kind: FindingKind::Vulnerability,
            severity: $severity,
            originalSeverity: null,
            ruleId: 'runtime-'.str_replace('_', '-', $row['status']),
            title: $text('title'),
            message: trim($message),
            path: $declaration->path,
            startLine: $declaration->line,
            endLine: $declaration->line,
            installedVersion: $declaration->version,
            fixedVersion: $hasTarget ? (string) ($row['target_newest'] ?? $row['target']) : null,
            advisoryUrl: $row['url'] ?? null,
        );
    }

    /**
     * The finding with a last sentence naming the other files that declare the same version (at most five).
     *
     * @param  list<string>  $places
     */
    private function withPlaces(Finding $finding, array $places): Finding
    {
        $listed = implode(', ', array_slice($places, 0, 5)).(count($places) > 5 ? ', …' : '');

        return new Finding(
            tool: $finding->tool,
            kind: $finding->kind,
            severity: $finding->severity,
            originalSeverity: $finding->originalSeverity,
            ruleId: $finding->ruleId,
            title: $finding->title,
            message: $finding->message.' '.trans('consulting.runtime.also_declared', ['places' => $listed], 'en'),
            path: $finding->path,
            startLine: $finding->startLine,
            endLine: $finding->endLine,
            installedVersion: $finding->installedVersion,
            fixedVersion: $finding->fixedVersion,
            advisoryUrl: $finding->advisoryUrl,
        );
    }

    /**
     * A row of the table. Dates are Y-m-d, as the sources give them.
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function row(Declaration $declaration, string $status, array $more = [], ?string $reason = null, ?string $url = null): array
    {
        $product = Products::all()[$declaration->product];

        return [
            'product' => $declaration->product,
            'label' => $product['label'],
            'kind' => $product['kind'],
            'declared' => $declaration->declared,
            'version' => $declaration->version,
            'is_range' => $declaration->isRange,
            'path' => $declaration->path,
            'line' => $declaration->line,
            'status' => $status,
            'release_line' => null,
            'supported_until' => null,
            'newest' => null,
            'target' => null,
            'target_newest' => null,
            'missing_security_releases' => null,
            'reason' => $reason,
            'url' => $url,
            ...$more,
        ];
    }

    /**
     * The release line a version belongs to: "8.3.4" → "8.3" (PHP), "20.11.0" → "20" (Node.js). A bare "8" is read as "8.0".
     *
     * @param  list<array<string, mixed>>  $releases
     * @return array<string, mixed>|null
     */
    private function releaseOf(string $version, array $releases): ?array
    {
        $parts = explode('.', $version);
        $names = array_column($releases, null, 'name');

        for ($length = min(count($parts), 3); $length >= 1; $length--) {
            $name = implode('.', array_slice($parts, 0, $length));

            if (isset($names[$name])) {
                return $names[$name];
            }
        }

        return $names["{$parts[0]}.0"] ?? null;
    }

    /**
     * Where to move to: the newest maintained line (the newest LTS one when the product has them), if newer than the declared one.
     *
     * @param  list<array<string, mixed>>  $releases
     * @return array<string, mixed>|null
     */
    private function recommendedLine(Declaration $declaration, array $releases): ?array
    {
        $maintained = array_values(array_filter($releases, fn (array $release): bool => $release['is_maintained'] && ! $release['is_eol']));
        $lts = array_values(array_filter($maintained, fn (array $release): bool => $release['is_lts']));
        $target = ($lts[0] ?? $maintained[0] ?? null);

        return $target !== null && version_compare($target['name'], $declaration->version, '>') ? $target : null;
    }

    /**
     * How many security releases of the declared line came after the declared version (Node.js, PHP); null when unknown.
     */
    private function missingSecurityReleases(Declaration $declaration, string $line): ?int
    {
        try {
            $releases = $this->data->securityFlags($declaration->product);
        } catch (Throwable) {
            return null;
        }

        if ($releases === []) {
            return null;
        }

        return count(array_filter($releases, fn (array $release): bool => $release['security']
            && str_starts_with($release['version'], "{$line}.")
            && version_compare($release['version'], $declaration->version, '>')));
    }

    /**
     * "2022-11-28" → "28 Nov 2022" (English, for the stored text).
     */
    private function date(?string $date): string
    {
        return $date === null ? '' : Carbon::parse($date)->locale('en')->format('j M Y');
    }

    private static function millisecondsSince(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
