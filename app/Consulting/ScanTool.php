<?php

namespace App\Consulting;

use App\Enums\ScanToolStatus;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One tool of the „Consultanță” report (Semgrep, OSV-Scanner, scc). Each tool says only what is its own:
 * where it is installed, its command and how to read its output. run() does the same for all of them:
 *
 * 1. a tool that is not installed is reported as such (the report still works without it);
 * 2. it asks the version, then runs the command through ToolProcess (minimal environment, timeout,
 *    output cap) and measures the duration;
 * 3. it reads the output; a timeout, an output too big, an exit code that means an error or an output
 *    that is not the expected JSON become a failed tool with a reason code, never a crash;
 * 4. it keeps at most max_findings problems, the most serious first, and counts the ones left out.
 */
abstract class ScanTool
{
    public function __construct(protected ToolProcess $process) {}

    /**
     * The key of the tool in consulting_reports.tools and consulting_findings.tool, e.g. "semgrep".
     */
    abstract public function key(): string;

    /**
     * The name shown on the page, e.g. "Semgrep".
     */
    abstract public function name(): string;

    /**
     * The path of the tool's executable (config gitchronicle.consulting).
     */
    abstract protected function executable(): string;

    /**
     * The command that analyzes the clone and writes JSON on the standard output.
     *
     * @return list<string>
     */
    abstract protected function command(string $clonePath): array;

    /**
     * Read the tool's answer.
     *
     * @throws ScanToolException when the answer is not what the tool writes after a successful run
     */
    abstract protected function read(ProcessResult $result, string $clonePath): ToolOutput;

    /**
     * Whether the tool's executable exists on this computer.
     */
    public function isInstalled(): bool
    {
        return is_file($this->executable()) && is_executable($this->executable());
    }

    /**
     * Run the tool on a clone and say what happened.
     */
    public function run(string $clonePath): ToolRun
    {
        if (! $this->isInstalled()) {
            return new ToolRun($this->key(), $this->name(), ScanToolStatus::NotInstalled);
        }

        $startedAt = hrtime(true);
        $version = null;

        try {
            $version = $this->version($clonePath);
            $result = $this->process->run($this->name(), $this->command($clonePath), $clonePath, $this->timeoutSeconds());
            $output = $this->read($result, $clonePath);
        } catch (ScanToolException $exception) {
            return new ToolRun(
                $this->key(),
                $this->name(),
                $exception->reason === ScanToolException::TIMED_OUT ? ScanToolStatus::TimedOut : ScanToolStatus::Failed,
                version: $version,
                durationMs: self::millisecondsSince($startedAt),
                error: $exception->reason,
                errorValue: $exception->value,
            );
        }

        $findings = collect($output->findings)
            ->sortBy(fn (Finding $finding): array => [$finding->severity->rank(), $finding->path ?? $finding->package ?? '', $finding->startLine ?? 0])
            ->values();
        $maxFindings = (int) config('gitchronicle.consulting.max_findings');

        return new ToolRun(
            $this->key(),
            $this->name(),
            ScanToolStatus::Ran,
            version: $version,
            durationMs: self::millisecondsSince($startedAt),
            findings: $findings->take($maxFindings)->all(),
            omittedCount: max(0, $findings->count() - $maxFindings),
            details: $output->details,
        );
    }

    /**
     * How long the tool may run (config gitchronicle.consulting.timeouts).
     */
    protected function timeoutSeconds(): int
    {
        return (int) config("gitchronicle.consulting.timeouts.{$this->key()}", 120);
    }

    /**
     * The version of the tool: the first "1.2.3" in what `--version` prints, or null.
     */
    protected function version(string $clonePath): ?string
    {
        $result = $this->process->run($this->name(), [$this->executable(), '--version'], $clonePath, 30);

        return preg_match('/\d+\.\d+\.\d+/', $result->output(), $matches) === 1 ? $matches[0] : null;
    }

    /**
     * The JSON a tool wrote, decoded; anything else means the run did not work.
     *
     * @return array<mixed>
     */
    protected function decode(ProcessResult $result): array
    {
        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded)) {
            Log::error('A consulting tool wrote an unreadable answer.', [
                'tool' => $this->key(),
                'exit_code' => $result->exitCode(),
                'output' => Str::limit($result->output(), 500),
                'error_output' => Str::limit($result->errorOutput(), 2000),
            ]);

            throw new ScanToolException(ScanToolException::UNREADABLE_OUTPUT, $result->exitCode());
        }

        return $decoded;
    }

    /**
     * A path written by a tool, relative to the clone: "/…/repositories/1/composer.lock" → "composer.lock",
     * "./app/Foo.php" → "app/Foo.php".
     */
    protected static function relativePath(string $path, string $clonePath): string
    {
        $path = Str::after($path, rtrim($clonePath, '/').'/');

        return ltrim(Str::startsWith($path, './') ? substr($path, 2) : $path, '/');
    }

    /**
     * The milliseconds passed since a hrtime(true) moment.
     */
    private static function millisecondsSince(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
