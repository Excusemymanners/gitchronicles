<?php

namespace Tests\Concerns;

use App\Models\Repository;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Stands in for Semgrep, OSV-Scanner and scc, so a test never starts them and never reaches osv.dev:
 * each tool is an empty executable file on the fake "local" disk (so it looks installed) and Process::fake
 * answers its command with the output the real tool wrote once on a tiny fixture project
 * (tests/Fixtures/consulting, recorded with Semgrep 1.182.1, OSV-Scanner 2.7.0 and scc 4.1.0).
 *
 * The fixture project had a PHP controller and model, a Blade view, two TypeScript files, a Python script
 * and three lock files (composer.lock with laravel/framework v13.0.0, package-lock.json with lodash 4.17.15
 * and react 18.3.1, requirements.txt with PyYAML 5.3). OSV-Scanner writes absolute paths: the folder of the
 * fixture project was replaced by /scan/project in its recorded output, nothing else was changed, and the fake
 * puts the folder it was asked to scan in its place.
 */
trait FakesConsultingTools
{
    /**
     * Every command the code ran, in order.
     *
     * @var list<list<string>>
     */
    protected array $toolCommands = [];

    /**
     * The environment of every command, in the same order.
     *
     * @var list<array<string, mixed>>
     */
    protected array $toolEnvironments = [];

    /**
     * Fake the three tools.
     *
     * @param  list<string>  $missing  the tools that are not installed: "semgrep", "osv-scanner", "scc"
     * @param  array<string, mixed>  $answers  what a tool answers instead of its fixture: a string (the output),
     *                                         an int (only the exit code) or "timeout"
     */
    protected function fakeConsultingTools(array $missing = [], array $answers = []): void
    {
        $disk = Storage::disk('local');

        foreach (['semgrep' => 'semgrep_path', 'osv-scanner' => 'osv_scanner_path', 'scc' => 'scc_path'] as $tool => $setting) {
            $path = $disk->path("scanners/{$tool}");

            if (! in_array($tool, $missing, true)) {
                $disk->put("scanners/{$tool}", '');
                chmod($path, 0755);
            }

            config(["gitchronicle.consulting.{$setting}" => $path]);
        }

        config([
            'gitchronicle.consulting.home' => $disk->path('scanners-home'),
            'gitchronicle.consulting.rules_version_file' => $disk->path('scanners-home/rules-version.json'),
        ]);

        Process::fake(function (PendingProcess $process) use ($answers) {
            $command = array_values((array) $process->command);
            $this->toolCommands[] = $command;
            $this->toolEnvironments[] = $process->environment;
            $tool = basename($command[0]);

            if (($command[1] ?? null) === '--version') {
                return Process::result(output: ['semgrep' => "1.182.1\n", 'osv-scanner' => "osv-scanner version: 2.7.0\ncommit: n/a\n", 'scc' => "scc version 4.1.0\n"][$tool] ?? '');
            }

            $answer = $answers[$tool] ?? null;

            return match (true) {
                $answer === 'timeout' => new SymfonyTimeoutException(new SymfonyProcess([$tool]), SymfonyTimeoutException::TYPE_GENERAL),
                is_int($answer) => Process::result(exitCode: $answer),
                is_string($answer) => Process::result(output: $answer),
                $tool === 'osv-scanner' => Process::result(output: str_replace('/scan/project', (string) end($command), $this->consultingFixture('osv-scanner.json')), exitCode: 1),
                default => Process::result(output: $this->consultingFixture("{$tool}.json")),
            };
        });
    }

    /**
     * The output a tool wrote on the fixture project.
     */
    protected function consultingFixture(string $file): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/consulting/{$file}"));
    }

    /**
     * Make the clone folder of a repository exist on the fake disk.
     */
    protected function makeClone(Repository $repository): void
    {
        Storage::disk('local')->makeDirectory($repository->cloneDirectory());
    }
}
