<?php

namespace App\Consulting;

use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Str;

/**
 * OSV-Scanner: the dependencies (from the lock files of the clone) with known vulnerabilities, from the
 * public OSV database (osv.dev, run by Google).
 *
 * What leaves the computer: the ecosystem, name and version of every package of the lock files (and then
 * the ids of the vulnerabilities found, for their details) go to api.osv.dev. The repositories are public,
 * so this is public information. Nothing else is sent: only the lock file plugins run (no hashes of files),
 * the transitive resolution through deps.dev is off and so is the call analysis (for Rust it runs build scripts).
 *
 * "--no-ignore": the clone holds only the files git tracks, but without this flag OSV-Scanner also applies
 * the .gitignore of the folders above the clone (this application's own repository ignores storage/) and
 * then sees nothing.
 */
final class OsvScanner extends ScanTool
{
    /**
     * OSV-Scanner's exit codes: 0 = nothing vulnerable, 1 = vulnerabilities found,
     * 128 = no lock file or manifest found. Anything else is an error.
     */
    private const NO_PACKAGES_FOUND = 128;

    public function key(): string
    {
        return 'osv-scanner';
    }

    public function name(): string
    {
        return 'OSV-Scanner';
    }

    protected function executable(): string
    {
        return (string) config('gitchronicle.consulting.osv_scanner_path');
    }

    /**
     * @return list<string>
     */
    protected function command(string $clonePath): array
    {
        return [
            $this->executable(), 'scan', 'source',
            '--recursive', '--no-ignore',
            '--no-resolve', '--no-call-analysis=all',
            '--x-no-default-plugins', '--x-plugins', 'lockfile',
            '--all-packages',
            '--format', 'json',
            $clonePath,
        ];
    }

    protected function read(ProcessResult $result, string $clonePath): ToolOutput
    {
        if ($result->exitCode() === self::NO_PACKAGES_FOUND) {
            return new ToolOutput([], ['packages_scanned' => 0, 'lockfiles' => []]);
        }

        $answer = $this->decode($result);

        if (! in_array($result->exitCode(), [0, 1], true) || ! is_array($answer['results'] ?? null)) {
            throw new ScanToolException(ScanToolException::EXIT_CODE, $result->exitCode());
        }

        $findings = [];
        $packageCount = 0;
        $lockfiles = [];

        foreach ($answer['results'] as $source) {
            $lockfile = self::relativePath((string) ($source['source']['path'] ?? ''), $clonePath);
            $lockfiles[] = $lockfile;

            foreach ($source['packages'] ?? [] as $entry) {
                $packageCount++;

                foreach ($this->packageFindings($entry, $lockfile) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        return new ToolOutput($findings, [
            'packages_scanned' => $packageCount,
            'lockfiles' => array_slice(array_values(array_unique($lockfiles)), 0, 20),
        ]);
    }

    /**
     * One finding per vulnerability group of a package: OSV-Scanner groups the advisories that are the same
     * vulnerability (a GHSA and its PYSEC alias, for example) and gives the group its highest CVSS score.
     *
     * @param  array<string, mixed>  $entry
     * @return list<Finding>
     */
    private function packageFindings(array $entry, string $lockfile): array
    {
        $package = $entry['package'] ?? [];
        $name = (string) ($package['name'] ?? '');
        $version = (string) ($package['version'] ?? '');
        $ecosystem = (string) ($package['ecosystem'] ?? '');
        $vulnerabilities = collect($entry['vulnerabilities'] ?? [])->filter(fn (mixed $vulnerability): bool => is_array($vulnerability))->keyBy('id');

        $findings = [];

        foreach ($entry['groups'] ?? [] as $group) {
            $id = (string) ($group['ids'][0] ?? '');

            if ($id === '' || $name === '') {
                continue;
            }

            // The advisories of the group, the group's own id first (a PYSEC entry may have no summary,
            // while its GHSA alias has one).
            $advisories = $vulnerabilities->only($group['ids'] ?? [])->sortBy(fn (array $vulnerability): bool => $vulnerability['id'] !== $id);
            [$severity, $originalSeverity] = $this->severity($group, $advisories->all());

            $findings[] = new Finding(
                tool: $this->key(),
                kind: FindingKind::Vulnerability,
                severity: $severity,
                originalSeverity: $originalSeverity,
                ruleId: $id,
                title: $this->title($advisories->all(), $id),
                message: (string) $advisories->pluck('details')->filter(fn (mixed $details): bool => is_string($details) && trim($details) !== '')->first(),
                path: $lockfile,
                package: $name,
                ecosystem: $ecosystem,
                installedVersion: $version,
                fixedVersion: $advisories->map(fn (array $vulnerability): ?string => $this->fixedVersion($vulnerability, $name, $version))->filter()->first(),
                advisoryId: $id,
                advisoryUrl: 'https://osv.dev/vulnerability/'.rawurlencode($id),
            );
        }

        return $findings;
    }

    /**
     * The severity of a group: its CVSS score when it has one ("max_severity", e.g. "7.5"), otherwise
     * the label of the advisory database (GitHub's "HIGH", "MODERATE", …), otherwise unknown (info).
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, array<string, mixed>>  $vulnerabilities
     * @return array{FindingSeverity, string|null}
     */
    private function severity(array $group, array $vulnerabilities): array
    {
        $score = $group['max_severity'] ?? '';

        if (is_numeric($score) && (float) $score > 0) {
            return [FindingSeverity::fromCvssScore((float) $score), 'CVSS '.$score];
        }

        foreach ($vulnerabilities as $vulnerability) {
            $label = $vulnerability['database_specific']['severity'] ?? null;

            if (is_string($label) && ($severity = FindingSeverity::fromAdvisoryLabel($label)) !== null) {
                return [$severity, strtoupper($label)];
            }
        }

        return [FindingSeverity::Info, null];
    }

    /**
     * The first one-line summary of the group's advisories, or the first line of their details, or the id.
     *
     * @param  array<string, array<string, mixed>>  $advisories
     */
    private function title(array $advisories, string $id): string
    {
        foreach ($advisories as $advisory) {
            $summary = trim((string) ($advisory['summary'] ?? ''));

            if ($summary !== '') {
                return $summary;
            }
        }

        foreach ($advisories as $advisory) {
            $firstLine = trim(Str::before(trim((string) ($advisory['details'] ?? '')), "\n"), " #\t");

            if ($firstLine !== '') {
                return $firstLine;
            }
        }

        return $id;
    }

    /**
     * The version that fixes the vulnerability for the installed version: the "fixed" event of the affected
     * range that contains it (introduced ≤ installed < fixed), compared with PHP's version_compare after
     * dropping a leading "v". Null when the advisory has no fix or the range cannot be found.
     *
     * @param  array<string, mixed>  $vulnerability
     */
    private function fixedVersion(array $vulnerability, string $package, string $installed): ?string
    {
        $installed = ltrim($installed, 'vV');

        foreach ($vulnerability['affected'] ?? [] as $affected) {
            if (strcasecmp((string) ($affected['package']['name'] ?? ''), $package) !== 0) {
                continue;
            }

            foreach ($affected['ranges'] ?? [] as $range) {
                if (! in_array($range['type'] ?? '', ['ECOSYSTEM', 'SEMVER'], true)) {
                    continue;
                }

                $introduced = '0';

                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['introduced'])) {
                        $introduced = ltrim((string) $event['introduced'], 'vV');
                    }

                    if (isset($event['fixed'])) {
                        $fixed = ltrim((string) $event['fixed'], 'vV');
                        $isAfterIntroduced = $introduced === '0' || version_compare($installed, $introduced, '>=');

                        if ($isAfterIntroduced && version_compare($installed, $fixed, '<')) {
                            return $fixed;
                        }
                    }
                }
            }
        }

        return null;
    }
}
