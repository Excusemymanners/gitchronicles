<?php

namespace Tests\Feature\Consulting;

use App\Consulting\Finding;
use App\Consulting\OsvScanner;
use App\Consulting\ScanToolException;
use App\Consulting\Scc;
use App\Consulting\Semgrep;
use App\Consulting\ToolRun;
use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use App\Enums\ScanToolStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesConsultingTools;
use Tests\TestCase;

/**
 * How each tool's real output (recorded once on the fixture project, see FakesConsultingTools) is read:
 * the findings with their normalized severity, the details, and what happens when a tool is missing,
 * runs out of time, writes something unreadable or too much, or finds more problems than the cap.
 */
class ScanToolsTest extends TestCase
{
    use FakesConsultingTools;

    private string $clonePath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('local')->makeDirectory('repositories/1');
        $this->clonePath = Storage::disk('local')->path('repositories/1');
    }

    public function test_semgrep_findings_are_read_with_their_rule_file_lines_kind_and_severity(): void
    {
        $this->fakeConsultingTools();

        $run = app(Semgrep::class)->run($this->clonePath);

        $this->assertSame(ScanToolStatus::Ran, $run->status);
        $this->assertSame('1.182.1', $run->version);
        $this->assertCount(17, $run->findings);
        $this->assertSame(['files_scanned' => 10, 'errors' => 0, 'rules' => null], $run->details);

        $shell = $this->findingOf($run, 'php-shell-command');
        $this->assertSame(FindingKind::Vulnerability, $shell->kind);
        $this->assertSame(FindingSeverity::High, $shell->severity);
        $this->assertSame('ERROR', $shell->originalSeverity);
        $this->assertSame('Shell command built from a variable', $shell->title);
        $this->assertSame('app/Http/ReportController.php', $shell->path);
        $this->assertSame([14, 14], [$shell->startLine, $shell->endLine]);

        $env = $this->findingOf($run, 'laravel-env-outside-config');
        $this->assertSame(FindingKind::Bug, $env->kind);
        $this->assertSame(FindingSeverity::Medium, $env->severity);

        $bareExcept = $this->findingOf($run, 'python-bare-except');
        $this->assertSame(FindingSeverity::Low, $bareExcept->severity);
        $this->assertSame('INFO', $bareExcept->originalSeverity);
        $this->assertSame([9, 12], [$bareExcept->startLine, $bareExcept->endLine]);

        // The most serious first.
        $this->assertSame(FindingSeverity::High, $run->findings[0]->severity);
        $this->assertSame(FindingSeverity::Low, $run->findings[16]->severity);
    }

    public function test_semgrep_runs_with_the_local_rules_and_without_metrics_in_the_clone(): void
    {
        $this->fakeConsultingTools();

        app(Semgrep::class)->run($this->clonePath);

        $command = $this->toolCommands[1];
        $this->assertSame('scan', $command[1]);
        $this->assertContains(base_path('scanners/rules'), $command);
        $this->assertSame(['--metrics', 'off'], array_slice($command, array_search('--metrics', $command, true), 2));
        $this->assertContains('--disable-version-check', $command);
        $this->assertSame('.', end($command));
        Process::assertRan(fn ($process) => $process->path === $this->clonePath);
    }

    public function test_osv_scanner_findings_have_the_package_versions_and_advisory(): void
    {
        $this->fakeConsultingTools();
        Carbon::setTestNow('2026-11-12 12:00:00');

        $run = app(OsvScanner::class)->run($this->clonePath);

        $this->assertSame(ScanToolStatus::Ran, $run->status);
        $this->assertSame('2.7.0', $run->version);
        $this->assertCount(12, $run->findings);
        $this->assertSame(['packages_scanned' => 4, 'lockfiles' => ['composer.lock', 'package-lock.json', 'requirements.txt'], 'queried_at' => '2026-11-12T12:00:00+00:00'], $run->details);

        $prototypePollution = $this->findingOf($run, 'GHSA-p6mc-m468-83gw');
        $this->assertSame(FindingKind::Vulnerability, $prototypePollution->kind);
        $this->assertSame(FindingSeverity::High, $prototypePollution->severity);
        $this->assertSame('CVSS 7.4', $prototypePollution->originalSeverity);
        $this->assertSame('Prototype Pollution in lodash', $prototypePollution->title);
        $this->assertSame(['lodash', 'npm', '4.17.15', '4.17.19'], [$prototypePollution->package, $prototypePollution->ecosystem, $prototypePollution->installedVersion, $prototypePollution->fixedVersion]);
        $this->assertSame('package-lock.json', $prototypePollution->path);
        $this->assertSame('https://osv.dev/vulnerability/GHSA-p6mc-m468-83gw', $prototypePollution->advisoryUrl);

        // A CVSS score of 9 or more is critical; the fix is found in the range that holds the installed version.
        $pyyaml = $this->findingOf($run, 'PYSEC-2020-96');
        $this->assertSame(FindingSeverity::Critical, $pyyaml->severity);
        $this->assertSame('5.3.1', $pyyaml->fixedVersion);
        $this->assertNotSame('PYSEC-2020-96', $pyyaml->title, 'The title comes from the advisory, not the id.');

        // Composer writes "v13.0.0"; the fix is the one of the 13.x range, not of 12.x.
        $laravel = $this->findingOf($run, 'GHSA-5vg9-5847-vvmq');
        $this->assertSame('v13.0.0', $laravel->installedVersion);
        $this->assertSame('13.10.0', $laravel->fixedVersion);
        $this->assertSame(FindingSeverity::Low, $this->findingOf($run, 'GHSA-jh5r-qr3c-85q8')->severity);
        $this->assertSame('13.30.0', $this->findingOf($run, 'GHSA-jh5r-qr3c-85q8')->fixedVersion);
    }

    public function test_osv_scanner_sends_only_lock_file_packages(): void
    {
        $this->fakeConsultingTools();

        app(OsvScanner::class)->run($this->clonePath);

        $command = $this->toolCommands[1];
        $this->assertSame(['scan', 'source'], array_slice($command, 1, 2));
        $this->assertContains('--no-resolve', $command);
        $this->assertContains('--no-call-analysis=all', $command);
        $this->assertSame(['--x-plugins', 'lockfile'], array_slice($command, array_search('--x-plugins', $command, true), 2));
        $this->assertContains('--x-no-default-plugins', $command);
    }

    public function test_a_project_without_lock_files_has_no_vulnerable_dependencies(): void
    {
        $this->fakeConsultingTools(answers: ['osv-scanner' => 128]);

        $run = app(OsvScanner::class)->run($this->clonePath);

        $this->assertSame(ScanToolStatus::Ran, $run->status);
        $this->assertSame([], $run->findings);
        $this->assertSame(['packages_scanned' => 0, 'lockfiles' => []], $run->details);
    }

    public function test_scc_counts_the_languages_biggest_first(): void
    {
        $this->fakeConsultingTools();

        $run = app(Scc::class)->run($this->clonePath);

        $this->assertSame(ScanToolStatus::Ran, $run->status);
        $this->assertSame('4.1.0', $run->version);
        $this->assertSame([], $run->findings);
        $this->assertSame(['PHP', 'TypeScript', 'Python', 'Blade template', 'Plain Text'], array_column($run->details['languages'], 'name'));
        $this->assertSame(['name' => 'PHP', 'files' => 3, 'lines' => 41, 'code' => 30, 'comments' => 0, 'blanks' => 11, 'complexity' => 0], $run->details['languages'][0]);
        $this->assertSame(array_sum(array_column($run->details['languages'], 'code')), $run->details['totals']['code']);
    }

    public function test_a_missing_tool_is_reported_without_running_anything(): void
    {
        $this->fakeConsultingTools(missing: ['semgrep']);

        $run = app(Semgrep::class)->run($this->clonePath);

        $this->assertSame(ScanToolStatus::NotInstalled, $run->status);
        $this->assertNull($run->version);
        Process::assertNothingRan();
    }

    public function test_a_tool_that_runs_out_of_time_is_stopped(): void
    {
        $this->fakeConsultingTools(answers: ['semgrep' => 'timeout']);

        $run = app(Semgrep::class)->run($this->clonePath);

        $this->assertSame(ScanToolStatus::TimedOut, $run->status);
        $this->assertSame(ScanToolException::TIMED_OUT, $run->error);
        $this->assertSame(300, $run->errorValue);
        $this->assertSame([], $run->findings);
    }

    public function test_an_unreadable_answer_or_an_error_exit_code_fails_the_tool(): void
    {
        $this->fakeConsultingTools(answers: ['semgrep' => 'Traceback (most recent call last): …', 'osv-scanner' => '{"results": []', 'scc' => 2]);

        $semgrep = app(Semgrep::class)->run($this->clonePath);
        $osv = app(OsvScanner::class)->run($this->clonePath);
        $scc = app(Scc::class)->run($this->clonePath);

        $this->assertSame([ScanToolStatus::Failed, ScanToolException::UNREADABLE_OUTPUT, 0], [$semgrep->status, $semgrep->error, $semgrep->errorValue]);
        $this->assertSame([ScanToolStatus::Failed, ScanToolException::UNREADABLE_OUTPUT], [$osv->status, $osv->error]);
        $this->assertSame([ScanToolStatus::Failed, ScanToolException::UNREADABLE_OUTPUT, 2], [$scc->status, $scc->error, $scc->errorValue]);
    }

    public function test_a_json_answer_without_results_fails_the_tool(): void
    {
        $this->fakeConsultingTools(answers: ['semgrep' => '{"errors": [{"message": "invalid rule"}]}']);

        $run = app(Semgrep::class)->run($this->clonePath);

        $this->assertSame([ScanToolStatus::Failed, ScanToolException::EXIT_CODE], [$run->status, $run->error]);
    }

    public function test_an_answer_bigger_than_the_cap_fails_the_tool(): void
    {
        config(['gitchronicle.consulting.max_output_bytes' => 1024 * 1024]);
        $this->fakeConsultingTools(answers: ['semgrep' => str_repeat('x', 1024 * 1024 + 1)]);

        $run = app(Semgrep::class)->run($this->clonePath);

        $this->assertSame([ScanToolStatus::Failed, ScanToolException::OUTPUT_TOO_LARGE, 1], [$run->status, $run->error, $run->errorValue]);
    }

    public function test_only_the_most_serious_findings_are_kept_and_the_rest_are_counted(): void
    {
        config(['gitchronicle.consulting.max_findings' => 5]);
        $this->fakeConsultingTools();

        $run = app(OsvScanner::class)->run($this->clonePath);

        $this->assertCount(5, $run->findings);
        $this->assertSame(7, $run->omittedCount);
        $this->assertSame(['critical', 'critical', 'critical', 'high', 'high'], array_map(fn (Finding $finding): string => $finding->severity->value, $run->findings));
        $this->assertSame(5, $run->toArray()['finding_count']);
        $this->assertSame(7, $run->toArray()['omitted_count']);
    }

    public function test_the_tools_get_a_minimal_environment_without_the_application_secrets(): void
    {
        putenv('APP_KEY=base64:secret-of-the-application');
        $_ENV['DB_PASSWORD'] = 'secret-of-the-database';
        $this->fakeConsultingTools();

        app(Scc::class)->run($this->clonePath);

        $environment = $this->toolEnvironments[1];
        $this->assertFalse($environment['APP_KEY']);
        $this->assertFalse($environment['DB_PASSWORD']);
        $this->assertEqualsCanonicalizing(
            ['PATH', 'HOME', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME', 'LANG', 'LC_ALL', 'SEMGREP_SEND_METRICS', 'SEMGREP_ENABLE_VERSION_CHECK', 'GIT_CONFIG_NOSYSTEM', 'GIT_TERMINAL_PROMPT'],
            array_keys(array_filter($environment, fn (mixed $value): bool => $value !== false)),
        );
        $this->assertSame('/usr/local/bin:/usr/bin:/bin', $environment['PATH']);
        $this->assertStringStartsWith(Storage::disk('local')->path('scanners-home'), $environment['HOME']);

        putenv('APP_KEY');
        unset($_ENV['DB_PASSWORD']);
    }

    public function test_texts_from_the_repository_are_cleaned_and_cut_before_they_are_saved(): void
    {
        $finding = new Finding(
            tool: 'semgrep',
            kind: FindingKind::Bug,
            severity: FindingSeverity::Low,
            originalSeverity: 'INFO',
            ruleId: 'rule',
            title: "Title\u{0007} with a bell",
            message: str_repeat('a', 5000),
            path: 'app/'.str_repeat('b', 600).'.php',
        );

        $attributes = $finding->toAttributes();

        $this->assertSame('Title with a bell', $attributes['title']);
        $this->assertSame(Finding::MESSAGE_LIMIT, mb_strlen($attributes['message']));
        $this->assertSame(500, mb_strlen($attributes['path']));
    }

    /**
     * The finding of a rule or an advisory.
     */
    private function findingOf(ToolRun $run, string $ruleId): Finding
    {
        foreach ($run->findings as $finding) {
            if ($finding->ruleId === $ruleId) {
                return $finding;
            }
        }

        $this->fail("No finding for {$ruleId}.");
    }
}
