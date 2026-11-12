<?php

namespace App\Consulting;

use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Str;

/**
 * Semgrep: bugs and security problems in the code, found with the rules of scanners/rules (written for
 * GitChronicle; scanners/README.md explains why the rules of the Semgrep Registry are not used).
 *
 * The command runs in the clone on ".", so every path in the answer is relative to it. Nothing leaves the
 * computer: the rules are local files, metrics and the version check are off. Folders of libraries and
 * build output are left out, like in the „Zone fierbinți” (Semgrep also skips its default .semgrepignore list).
 */
final class Semgrep extends ScanTool
{
    /**
     * Folders that hold code which is not the project's own.
     */
    private const EXCLUDED = ['vendor', 'node_modules', 'dist', 'build', '*.min.js'];

    public function key(): string
    {
        return 'semgrep';
    }

    public function name(): string
    {
        return 'Semgrep';
    }

    protected function executable(): string
    {
        return (string) config('gitchronicle.consulting.semgrep_path');
    }

    /**
     * "--no-rewrite-rule-ids" keeps the rule ids as written in the YAML files (php-eval), instead of
     * prefixing them with the folder of the rules. "--timeout 10" is per file and rule: a huge or
     * pathological file is skipped instead of blocking the whole run.
     *
     * @return list<string>
     */
    protected function command(string $clonePath): array
    {
        $exclusions = collect(self::EXCLUDED)->flatMap(fn (string $pattern): array => ['--exclude', $pattern])->all();

        return [
            $this->executable(), 'scan',
            '--config', (string) config('gitchronicle.consulting.rules_path'),
            '--json', '--quiet',
            '--metrics', 'off',
            '--disable-version-check',
            '--no-rewrite-rule-ids',
            '--timeout', '10',
            '--max-target-bytes', '1000000',
            ...$exclusions,
            '.',
        ];
    }

    /**
     * Semgrep exits with 0 when it ran (findings or not) and with another code on an error; the JSON is
     * only trusted when it has the list of results. "errors" are files it could not parse: counted, not fatal.
     */
    protected function read(ProcessResult $result, string $clonePath): ToolOutput
    {
        $answer = $this->decode($result);

        if (! is_array($answer['results'] ?? null)) {
            throw new ScanToolException(ScanToolException::EXIT_CODE, $result->exitCode());
        }

        $findings = collect($answer['results'])
            ->filter(fn (mixed $result): bool => is_array($result) && is_string($result['check_id'] ?? null) && is_string($result['path'] ?? null))
            ->map(fn (array $result): Finding => $this->finding($result, $clonePath))
            ->values()
            ->all();

        return new ToolOutput($findings, [
            'files_scanned' => count($answer['paths']['scanned'] ?? []),
            'errors' => count($answer['errors'] ?? []),
        ]);
    }

    /**
     * One result of Semgrep: the rule (its title and category come from the rule's metadata), the file and
     * the lines. A rule in the "security" category finds vulnerabilities; every other one finds bugs.
     *
     * @param  array<string, mixed>  $result
     */
    private function finding(array $result, string $clonePath): Finding
    {
        $extra = is_array($result['extra'] ?? null) ? $result['extra'] : [];
        $metadata = is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [];
        $ruleId = Str::afterLast($result['check_id'], '.');
        $severity = is_string($extra['severity'] ?? null) ? $extra['severity'] : 'INFO';
        $startLine = (int) ($result['start']['line'] ?? 0);

        return new Finding(
            tool: $this->key(),
            kind: ($metadata['category'] ?? null) === 'security' ? FindingKind::Vulnerability : FindingKind::Bug,
            severity: FindingSeverity::fromSemgrep($severity),
            originalSeverity: $severity,
            ruleId: $ruleId,
            title: is_string($metadata['title'] ?? null) ? $metadata['title'] : $ruleId,
            message: is_string($extra['message'] ?? null) ? $extra['message'] : '',
            path: self::relativePath($result['path'], $clonePath),
            startLine: $startLine > 0 ? $startLine : null,
            endLine: $startLine > 0 ? max($startLine, (int) ($result['end']['line'] ?? $startLine)) : null,
        );
    }
}
