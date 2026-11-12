<?php

namespace App\Consulting;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * scc (Sloc, Cloc and Code): how big the code is, per language — files, lines, lines of code, comments,
 * blank lines and an estimate of the complexity (the branches and loops it counts). It only reads files
 * and sends nothing anywhere. It finds no problems: its numbers are the „Statistici despre cod” section.
 *
 * Left out, like in the „Zone fierbinți”: library and build folders, minified or generated files
 * (--no-min-gen) and huge files (--no-large), so the numbers describe the project's own code.
 */
final class Scc extends ScanTool
{
    /**
     * At most this many languages are kept (scc knows a few hundred; a real project has a handful).
     */
    private const MAX_LANGUAGES = 30;

    public function key(): string
    {
        return 'scc';
    }

    public function name(): string
    {
        return 'scc';
    }

    protected function executable(): string
    {
        return (string) config('gitchronicle.consulting.scc_path');
    }

    /**
     * @return list<string>
     */
    protected function command(string $clonePath): array
    {
        return [
            $this->executable(),
            '--format', 'json',
            '--no-cocomo', '--no-min-gen', '--no-large',
            '--exclude-dir', '.git,.hg,.svn,vendor,node_modules,dist,build',
            '.',
        ];
    }

    /**
     * scc writes a list with one object per language; the languages are sorted by lines of code here.
     */
    protected function read(ProcessResult $result, string $clonePath): ToolOutput
    {
        $answer = $this->decode($result);

        if ($result->failed() || ! array_is_list($answer)) {
            throw new ScanToolException(ScanToolException::EXIT_CODE, $result->exitCode());
        }

        $languages = collect($answer)
            ->filter(fn (mixed $language): bool => is_array($language) && is_string($language['Name'] ?? null))
            ->map(fn (array $language): array => [
                'name' => $language['Name'],
                'files' => (int) ($language['Count'] ?? 0),
                'lines' => (int) ($language['Lines'] ?? 0),
                'code' => (int) ($language['Code'] ?? 0),
                'comments' => (int) ($language['Comment'] ?? 0),
                'blanks' => (int) ($language['Blank'] ?? 0),
                'complexity' => (int) ($language['Complexity'] ?? 0),
            ])
            ->sortBy([['code', 'desc'], ['name', 'asc']])
            ->take(self::MAX_LANGUAGES)
            ->values();

        $totals = [];

        foreach (['files', 'lines', 'code', 'comments', 'blanks', 'complexity'] as $column) {
            $totals[$column] = (int) $languages->sum($column);
        }

        return new ToolOutput([], ['languages' => $languages->all(), 'totals' => $totals]);
    }
}
