<?php

namespace App\Console\Commands;

use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Git\Git;
use App\Git\LocalClone;
use App\Jobs\AnalyzeRepository;
use App\Jobs\BuildDiagrams;
use App\Jobs\GenerateDocumentation;
use App\Jobs\IndexCode;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Search\CodeSearch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The experiment of the thesis (Pasul 7): incremental update versus processing from zero, measured
 * on the real history of a repository that GitChronicle already cloned.
 *
 *     php artisan gitchronicle:benchmark 3 --commits=5
 *
 * The newest N commits of the clone are replayed one by one. For each commit two things are measured:
 *
 * - "incremental": a copy of the repository that was processed at the previous commit is updated to
 *   this one, exactly as the pipeline does it (change set, impact, only the affected pages, ...);
 * - "de la zero": another copy, with nothing saved, is processed at this same commit as if it were new.
 *
 * Nothing of the real application is touched: the command works in a scratch folder with its own
 * SQLite database and its own copies of the clone (storage/app/private/benchmarks/scratch-…), deleted
 * at the end. The steps measured are the code analysis, the documentation, the diagrams and the search
 * index; cloning, fetching and the import of the commit list are left out (network time, the same for both).
 *
 * By default Claude is never called (the template writes the pages): the cost of the AI is measured as
 * the number of characters the writer would have received, and tokens are only ESTIMATED from them with
 * the characters-per-token ratio measured on the pages Claude really wrote in this installation (when
 * there are none, no token estimate is shown). With --ai the incremental copy uses the configured AI
 * driver, starting point included (one call per page: lower DOCS_MAX_MODULE_PAGES to keep it small),
 * and its real tokens are reported; the from-zero copy never calls Claude.
 *
 * The clone has file contents only for its newest commit, so git downloads from GitHub the older
 * contents the replayed commits need: the command needs the network once (the tests use a local repository).
 *
 * The results are printed and saved as JSON and CSV in storage/app/private/benchmarks/.
 */
#[Signature('gitchronicle:benchmark
    {repository : The id of a processed repository}
    {--commits=5 : How many of its newest commits to replay}
    {--ai : Use the configured AI driver for the incremental runs (real calls and tokens)}')]
#[Description('Measure the incremental update against processing from zero on the real history of a repository')]
class BenchmarkIncremental extends Command
{
    /**
     * The pipeline steps that are measured, in order, with their short names for the table.
     *
     * @var array<string, class-string>
     */
    private const STEPS = [
        'analyze' => AnalyzeRepository::class,
        'docs' => GenerateDocumentation::class,
        'diagrams' => BuildDiagrams::class,
        'index' => IndexCode::class,
    ];

    /**
     * How long git may take to move a scratch clone to a commit (it may download file contents).
     */
    private const CHECKOUT_TIMEOUT_SECONDS = 300;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = Repository::find($this->argument('repository'));
        $commitCount = max(1, (int) $this->option('commits'));

        if ($source === null) {
            $this->error("There is no repository with id {$this->argument('repository')}.");

            return self::FAILURE;
        }

        if (! is_dir($source->clonePath().'/.git')) {
            $this->error("#{$source->id} {$source->owner}/{$source->name} has no clone yet. Start the processing first: php artisan repositories:process {$source->id}");

            return self::FAILURE;
        }

        $commits = $this->newestCommits($source->clonePath(), $commitCount + 1);

        if (count($commits) < 2) {
            $this->error('The repository has only one commit: there is nothing to compare.');

            return self::FAILURE;
        }

        $charactersPerToken = $this->measuredCharactersPerToken();
        $gitFolder = $source->clonePath().'/.git';
        $results = $this->inScratchEnvironment(fn (): array => $this->replay($source, $gitFolder, $commits));

        $report = [
            'repository' => "{$source->owner}/{$source->name}",
            'generated_at' => now()->toIso8601String(),
            'ai_driver' => $this->option('ai') ? config('gitchronicle.ai_driver') : 'none',
            'characters_per_token' => $charactersPerToken,
            'note' => 'tokens_estimated = context_characters / characters_per_token (an estimate); the other numbers are measured.',
            ...$results,
        ];

        foreach ($report['commits'] as &$commit) {
            foreach (['incremental', 'from_zero'] as $kind) {
                $commit[$kind]['tokens_estimated'] = $charactersPerToken === null
                    ? null
                    : (int) round($commit[$kind]['context_characters'] / $charactersPerToken);
            }
        }
        unset($commit);

        $this->printReport($report);
        $this->saveReport($report);

        return self::SUCCESS;
    }

    /**
     * The newest commits of the clone on its main line (--first-parent), oldest first.
     *
     * @return list<array{sha: string, subject: string}>
     */
    private function newestCommits(string $clonePath, int $count): array
    {
        $output = Git::process($clonePath)
            ->run(['git', 'log', '--first-parent', "--max-count={$count}", '--format=%H %s', 'HEAD', '--'])
            ->throw()
            ->output();

        $commits = array_map(function (string $line): array {
            [$sha, $subject] = array_pad(explode(' ', $line, 2), 2, '');

            return ['sha' => $sha, 'subject' => mb_scrub($subject, 'UTF-8')];
        }, array_filter(explode("\n", trim($output))));

        return array_reverse($commits);
    }

    /**
     * How many characters of context make one input token, measured on the pages Claude wrote in this
     * installation (their context size and their real token count are both saved). Null when no such
     * page exists yet: then the report shows only characters.
     */
    private function measuredCharactersPerToken(): ?float
    {
        $pages = Document::query()
            ->where('writer', Document::WRITER_AI)
            ->whereNotNull('context_characters')
            ->where('input_tokens', '>', 0)
            ->selectRaw('sum(context_characters) as characters, sum(input_tokens) as tokens')
            ->first();

        return $pages === null || (int) $pages->tokens === 0 ? null : round($pages->characters / $pages->tokens, 2);
    }

    /**
     * Run the callback with a scratch database, a scratch "local" disk and a queue that runs every job
     * right away, then put everything back and delete the scratch folder, whatever happened.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function inScratchEnvironment(callable $callback): mixed
    {
        $realDisk = Storage::disk('local');
        $scratchDirectory = 'benchmarks/scratch-'.Str::lower(Str::random(8));
        $scratchPath = $realDisk->path($scratchDirectory);

        $realConnection = DB::getDefaultConnection();
        $realQueue = config('queue.default');
        $realAiDriver = config('gitchronicle.ai_driver');

        File::ensureDirectoryExists("{$scratchPath}/disk");
        File::put("{$scratchPath}/benchmark.sqlite", '');

        try {
            config([
                'database.connections.benchmark' => ['driver' => 'sqlite', 'database' => "{$scratchPath}/benchmark.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
                'queue.default' => 'sync',
                'gitchronicle.ai_driver' => $this->option('ai') ? $realAiDriver : 'none',
            ]);
            DB::setDefaultConnection('benchmark');
            Storage::set('local', Storage::build(['driver' => 'local', 'root' => "{$scratchPath}/disk"]));

            $this->callSilent('migrate', ['--database' => 'benchmark', '--force' => true]);

            return $callback();
        } finally {
            DB::setDefaultConnection($realConnection);
            DB::purge('benchmark');
            Storage::set('local', $realDisk);
            config(['queue.default' => $realQueue, 'gitchronicle.ai_driver' => $realAiDriver]);

            $realDisk->deleteDirectory($scratchDirectory);
        }
    }

    /**
     * Replay the commits (oldest first) in the scratch environment. The first commit is the starting
     * point: the incremental copy is processed there in full; every later commit is measured both ways.
     *
     * @param  string  $gitFolder  the .git folder of the real clone
     * @param  list<array{sha: string, subject: string}>  $commits
     * @return array{baseline: array<string, mixed>, commits: list<array<string, mixed>>}
     */
    private function replay(Repository $source, string $gitFolder, array $commits): array
    {
        $incrementalAiDriver = config('gitchronicle.ai_driver');
        $incremental = $this->scratchCopy($source, $gitFolder, $source->url);
        $fromZero = $this->scratchCopy($source, $gitFolder, $source->url.'#de-la-zero');

        $first = array_shift($commits);
        $this->line("Starting point: commit {$this->short($first['sha'])}, fully processed…");
        $baseline = $this->process($incremental, ProcessingMode::Initial, null, $first['sha']);

        $rows = [];
        $previousSha = $first['sha'];

        foreach ($commits as $commit) {
            $this->line("Commit {$this->short($commit['sha'])}: incremental, then from zero…");

            config(['gitchronicle.ai_driver' => $incrementalAiDriver]);
            $incrementalRun = $this->process($incremental, ProcessingMode::Incremental, $previousSha, $commit['sha']);

            // The from-zero copy never calls Claude: its cost is the size of the contexts it would send.
            config(['gitchronicle.ai_driver' => 'none']);
            $this->forgetEverything($fromZero);
            $fromZeroRun = $this->process($fromZero, ProcessingMode::Initial, null, $commit['sha']);

            $rows[] = [
                'sha' => $commit['sha'],
                'subject' => $commit['subject'],
                'files_total' => $incrementalRun['files_total'],
                'files_changed' => $incrementalRun['files_changed'],
                'modules_total' => $incrementalRun['modules_total'],
                'modules_affected' => $incrementalRun['modules_affected'],
                'incremental' => $incrementalRun,
                'from_zero' => $fromZeroRun,
            ];

            $previousSha = $commit['sha'];
        }

        return ['baseline' => ['sha' => $first['sha'], 'subject' => $first['subject'], ...$baseline], 'commits' => $rows];
    }

    /**
     * A repository row in the scratch database with its own copy of the clone's git data. Only .git is
     * copied: git writes the files of the wanted commit itself, when process() moves the clone there.
     */
    private function scratchCopy(Repository $source, string $gitFolder, string $url): Repository
    {
        $copy = Repository::create([
            'url' => $url,
            'owner' => $source->owner,
            'name' => $source->name,
            'status' => RepositoryStatus::Ready,
            'default_branch' => $source->default_branch,
        ]);

        File::copyDirectory($gitFolder, $copy->clonePath().'/.git');

        return $copy;
    }

    /**
     * Move the copy to a commit and run the measured pipeline steps on it, timing each one.
     *
     * @return array<string, mixed> the numbers of the run
     */
    private function process(Repository $repository, ProcessingMode $mode, ?string $from, string $to): array
    {
        $clone = LocalClone::of($repository);
        $clone->moveTo($to, self::CHECKOUT_TIMEOUT_SECONDS)->throw();

        $repository->update(['last_sha' => $to, 'cloned_at' => now()]);

        $run = $repository->processingRuns()->create([
            'mode' => $mode,
            'trigger' => ProcessingTrigger::Manual,
            'from_sha' => $from,
            'to_sha' => $to,
            'files_total' => $clone->trackedFileCount(),
            'status' => ProcessingRunStatus::Running,
            'started_at' => now(),
        ]);

        $stepMilliseconds = [];

        foreach (self::STEPS as $name => $step) {
            $startedAt = microtime(true);

            dispatch_sync(new $step($repository, $run));

            $stepMilliseconds[$name] = (int) round((microtime(true) - $startedAt) * 1000);
        }

        $run->refresh()->finish(ProcessingRunStatus::Succeeded);
        $repository->update(['status' => RepositoryStatus::Ready]);

        return $this->numbersOf($run, $stepMilliseconds);
    }

    /**
     * The numbers of one measured run, for the report.
     *
     * @param  array<string, int>  $stepMilliseconds
     * @return array<string, mixed>
     */
    private function numbersOf(ProcessingRun $run, array $stepMilliseconds): array
    {
        return [
            'mode' => $run->mode->value,
            'milliseconds' => array_sum($stepMilliseconds),
            'step_milliseconds' => $stepMilliseconds,
            'files_total' => $run->files_total,
            'files_changed' => $run->files_changed,
            'files_parsed' => $run->files_analyzed,
            'modules_total' => $run->modules_total,
            'modules_affected' => $run->modules_affected,
            'pages_written' => $run->documents_regenerated,
            'pages_kept' => $run->documents_kept,
            'context_characters' => $run->context_characters,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
        ];
    }

    /**
     * Make the from-zero copy new again: no analysis, no pages, no diagrams, no index and no analyzer cache.
     */
    private function forgetEverything(Repository $repository): void
    {
        Document::whereBelongsTo($repository)->delete();
        $repository->diagrams()->delete();
        $repository->diagramVersions()->delete();
        $repository->codeChunks()->delete();
        app(CodeSearch::class)->forget($repository);
        $repository->moduleDependencies()->delete();
        $repository->files()->delete();
        $repository->modules()->delete();

        Storage::disk('local')->delete($repository->analysisCacheFile());
    }

    /**
     * Print the measurements as a table, one row per replayed commit, and the totals under it.
     *
     * @param  array<string, mixed>  $report
     */
    private function printReport(array $report): void
    {
        $withTokens = $report['characters_per_token'] !== null;

        $this->newLine();
        $this->info("Incremental vs. from zero — {$report['repository']} (AI: {$report['ai_driver']})");

        $this->table(
            ['Commit', 'Files changed', 'Modules affected', 'Incr.: time', 'files parsed', 'pages written', 'characters', 'Zero: time', 'files parsed', 'pages written', 'characters'],
            array_map(fn (array $commit): array => [
                $this->short($commit['sha']),
                "{$commit['files_changed']} din {$commit['files_total']}",
                "{$commit['modules_affected']} din {$commit['modules_total']}".($commit['incremental']['mode'] === 'full' ? ' (full run)' : ''),
                $this->seconds($commit['incremental']['milliseconds']),
                $commit['incremental']['files_parsed'],
                $commit['incremental']['pages_written'],
                $this->characters($commit['incremental'], $withTokens),
                $this->seconds($commit['from_zero']['milliseconds']),
                $commit['from_zero']['files_parsed'],
                $commit['from_zero']['pages_written'],
                $this->characters($commit['from_zero'], $withTokens),
            ], $report['commits']),
        );

        $total = fn (string $kind, string $number): int => array_sum(array_map(fn (array $commit): int => $commit[$kind][$number], $report['commits']));
        $incrementalMs = $total('incremental', 'milliseconds');
        $fromZeroMs = $total('from_zero', 'milliseconds');
        $incrementalCharacters = $total('incremental', 'context_characters');
        $fromZeroCharacters = $total('from_zero', 'context_characters');

        $this->line('Total over '.count($report['commits'])." commits: incremental {$this->seconds($incrementalMs)}, from zero {$this->seconds($fromZeroMs)}"
            .($incrementalMs > 0 ? ' ('.number_format($fromZeroMs / $incrementalMs, 1).' times more)' : '').'.');
        $this->line('Pages written: '.$total('incremental', 'pages_written').' incremental, '.$total('from_zero', 'pages_written').' from zero. '
            .'Context characters: '.number_format($incrementalCharacters).' versus '.number_format($fromZeroCharacters).'.');

        if ($total('incremental', 'input_tokens') > 0) {
            $this->line('Real tokens (incremental, with AI): '.number_format($total('incremental', 'input_tokens')).' input, '
                .number_format($total('incremental', 'output_tokens')).' output.');
        }

        $this->line($withTokens
            ? "The tokens in parentheses are ESTIMATED: characters / {$report['characters_per_token']} (ratio measured on the pages Claude wrote in this installation)."
            : 'No token estimate: there are no pages written by Claude yet to measure the characters/token ratio on.');
        $this->line('Time = code analysis + documentation + diagrams + assistant index (without cloning, fetching and importing the commits).');
    }

    /**
     * Save the report as JSON (everything) and as CSV (one line per commit) in storage/app/private/benchmarks/.
     *
     * @param  array<string, mixed>  $report
     */
    private function saveReport(array $report): void
    {
        $name = 'benchmarks/'.Str::slug(str_replace('/', '-', $report['repository'])).'-'.now()->format('Ymd-His');

        $header = [
            'sha', 'files_total', 'files_changed', 'modules_total', 'modules_affected', 'incremental_mode',
            'incremental_ms', 'incremental_files_parsed', 'incremental_pages_written', 'incremental_pages_kept',
            'incremental_context_characters', 'incremental_tokens_estimated', 'incremental_input_tokens', 'incremental_output_tokens',
            'from_zero_ms', 'from_zero_files_parsed', 'from_zero_pages_written', 'from_zero_context_characters', 'from_zero_tokens_estimated',
        ];

        $lines = array_map(fn (array $commit): string => implode(',', [
            $commit['sha'], $commit['files_total'], $commit['files_changed'], $commit['modules_total'], $commit['modules_affected'], $commit['incremental']['mode'],
            $commit['incremental']['milliseconds'], $commit['incremental']['files_parsed'], $commit['incremental']['pages_written'], $commit['incremental']['pages_kept'],
            $commit['incremental']['context_characters'], $commit['incremental']['tokens_estimated'], $commit['incremental']['input_tokens'], $commit['incremental']['output_tokens'],
            $commit['from_zero']['milliseconds'], $commit['from_zero']['files_parsed'], $commit['from_zero']['pages_written'], $commit['from_zero']['context_characters'], $commit['from_zero']['tokens_estimated'],
        ]), $report['commits']);

        Storage::disk('local')->put("{$name}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        Storage::disk('local')->put("{$name}.csv", implode("\n", [implode(',', $header), ...$lines])."\n");

        $this->info('Results saved to '.Storage::disk('local')->path("{$name}.json").' and .csv');
    }

    /**
     * Milliseconds as seconds with one decimal, for the table ("12,3 s").
     */
    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).' s';
    }

    /**
     * The context characters of a run and, when the ratio is known, the estimated tokens in brackets.
     *
     * @param  array<string, mixed>  $run
     */
    private function characters(array $run, bool $withTokens): string
    {
        $characters = number_format($run['context_characters'], 0, ',', '.');

        return $withTokens ? "{$characters} (≈ ".number_format($run['tokens_estimated']).' tokens)' : $characters;
    }

    /**
     * The first 7 characters of a sha.
     */
    private function short(string $sha): string
    {
        return substr($sha, 0, 7);
    }
}
