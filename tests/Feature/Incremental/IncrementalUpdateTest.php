<?php

namespace Tests\Feature\Incremental;

use App\Ai\AiException;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Documentation\AiDocumentationWriter;
use App\Incremental\FullProcessingReason;
use App\Models\CodeChunk;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Repository;
use App\Search\CodeSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\ProcessesFixtureProject;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The incremental update (Faza 9), end to end: the fixture project is processed once, a commit is
 * pushed to its origin, and the second run must redo only what that commit can reach.
 * Claude is a FakeTextGenerator that counts its calls; the code analyzer is the real one.
 */
class IncrementalUpdateTest extends TestCase
{
    use ProcessesFixtureProject;
    use RefreshDatabase;

    /**
     * An answer that has every heading of a module page and of the overview, so it is accepted for every page.
     */
    private const AI_PAGE = "# The page\n\n## Role\n\nText.\n\n## Main components\n\n## How it works\n\n## Dependencies\n\n## Files\n\n"
        ."## What the project does\n\n## Technologies\n\n## Architecture\n\n## Modules\n\n## How to run it\n";

    private const MONEY_WITH_A_NEW_METHOD = "<?php\n\nnamespace Shop\\Support;\n\nfinal class Money\n{\n    public static function format(int \$cents): string\n    {\n        return number_format(\$cents / 100, 2).' lei';\n    }\n\n    public static function zero(): string\n    {\n        return self::format(0);\n    }\n}\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessAnalyzerIsInstalled();
    }

    public function test_an_update_fetches_the_new_commit_into_the_same_clone(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        // A file git does not track survives only if the clone folder is kept, not cloned again.
        Storage::disk('local')->put("repositories/{$repository->id}/.git/kept-by-fetch", 'x');
        $newSha = $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $run = $this->process($repository);

        Storage::disk('local')->assertExists("repositories/{$repository->id}/.git/kept-by-fetch");
        $this->assertStringContainsString('function zero', Storage::disk('local')->get("repositories/{$repository->id}/src/Support/Money.php"));
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready', 'last_sha' => $newSha]);
        $this->assertDatabaseHas('processing_runs', [
            'id' => $run->id,
            'mode' => 'incremental',
            'status' => 'succeeded',
            'from_sha' => $firstRun->to_sha,
            'to_sha' => $newSha,
        ]);
        $this->assertSame('initial', $firstRun->mode->value);
        $this->assertNull($firstRun->from_sha);
    }

    public function test_the_run_records_what_changed_and_which_modules_it_affects(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $run = $this->process($repository);

        $this->assertSame(1, $run->files_changed);
        // Only the changed file was parsed again; the other 12 came from the analyzer's cache.
        $this->assertSame(1, $run->files_analyzed);
        $this->assertSame(3, $run->modules_affected);
        $this->assertSame(9, $run->modules_total);
        $this->assertSame(['added' => 0, 'modified' => 1, 'deleted' => 0], array_intersect_key($run->change_summary, array_flip(['added', 'modified', 'deleted'])));
        $this->assertSame([
            ['path' => 'src/Support', 'files' => 1, 'via' => 'changed'],
            ['path' => 'src/Http', 'files' => 0, 'via' => 'imports'],
            ['path' => 'src/Models', 'files' => 0, 'via' => 'imports'],
        ], $run->change_summary['modules']);
        $this->assertNull($run->change_summary['full_reason']);
        $this->assertContains('src/Support', $run->module_paths);
        $this->assertCount(9, $run->module_paths);
    }

    public function test_only_the_page_whose_context_changed_is_written_again(): void
    {
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([self::AI_PAGE]));
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        $callsOfTheFirstRun = count($this->documentationRequests($generator));
        $newSha = $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $run = $this->process($repository);

        $this->assertSame(11, $callsOfTheFirstRun);
        $newRequests = array_slice($this->documentationRequests($generator), $callsOfTheFirstRun);
        $this->assertCount(1, $newRequests);
        $this->assertStringContainsString('Module: src/Support', $newRequests[0]['user']);
        $this->assertSame(1, $run->documents_regenerated);
        $this->assertSame(10, $run->documents_kept);
        // One call for the page, one for the reading of the architecture diagrams and one for the flows of the
        // diagrams step: the module's page and its list of symbols changed, so both inputs are new.
        $this->assertSame(300, $run->input_tokens);
        $this->assertGreaterThan(0, $run->context_characters);
        $this->assertLessThan($firstRun->fresh()->context_characters, $run->context_characters);
        // The pages that were not written keep the commit they describe.
        $this->assertSame($newSha, Document::firstWhere('slug', 'src-support')->source_sha);
        $this->assertSame([$firstRun->to_sha], Document::where('slug', '!=', 'src-support')->distinct()->pluck('source_sha')->all());
    }

    public function test_a_module_is_checked_when_a_changed_module_starts_importing_it(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        $this->pushToOrigin(
            write: ['tools/cli/extra.py' => "from ..stats import average\n\n\ndef run():\n    return average([1])\n"],
            delete: ['frontend/lib/format.ts'],
        );

        $run = $this->process($repository);

        $this->assertSame(['added' => 1, 'modified' => 0, 'deleted' => 1], array_intersect_key($run->change_summary, array_flip(['added', 'modified', 'deleted'])));
        // frontend/lib lost its only file, so it is no longer a module: only its importers are listed.
        $this->assertSame([
            ['path' => 'tools/cli', 'files' => 1, 'via' => 'changed'],
            ['path' => 'frontend', 'files' => 0, 'via' => 'imports'],
            ['path' => 'frontend/components', 'files' => 0, 'via' => 'imports'],
            ['path' => 'tools', 'files' => 0, 'via' => 'imported'],
        ], $run->change_summary['modules']);
        $this->assertSame(['frontend/lib'], $run->change_summary['modules_removed']);
        // The page of a module that no longer exists is deleted; the overview is written again because the module list changed.
        $this->assertDatabaseMissing('documents', ['repository_id' => $repository->id, 'slug' => 'frontend-lib']);
        $this->assertSame($run->to_sha, Document::firstWhere('slug', Document::OVERVIEW)->source_sha);
        // "tools" has the same files, but tools/cli imports it more than before: its page changed too.
        $this->assertSame($run->to_sha, Document::firstWhere('slug', 'tools')->source_sha);
        $this->assertSame($run->from_sha, Document::firstWhere('slug', 'src-http')->source_sha);
    }

    public function test_the_same_commit_is_nothing_new_and_no_other_step_runs(): void
    {
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([self::AI_PAGE]));
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        $callsOfTheFirstRun = count($generator->requests);
        $analyzedAt = $repository->fresh()->analyzed_at;
        $this->travel(5)->minutes();

        $run = $this->process($repository);

        $this->assertDatabaseHas('processing_runs', [
            'id' => $run->id,
            'mode' => 'incremental',
            'status' => 'skipped',
            'from_sha' => $firstRun->to_sha,
            'to_sha' => $firstRun->to_sha,
            'files_changed' => 0,
            'documents_regenerated' => 0,
        ]);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
        $this->assertCount($callsOfTheFirstRun, $generator->requests);
        $this->assertEquals($analyzedAt, $repository->fresh()->analyzed_at);
    }

    public function test_more_changed_files_than_the_threshold_make_the_run_a_full_one(): void
    {
        config(['gitchronicle.incremental.full_threshold' => 0.10]);
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        $this->pushToOrigin([
            'src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD,
            'tools/stats.py' => "def average(values):\n    return 0\n",
        ]);

        $run = $this->process($repository);

        $this->assertSame('full', $run->mode->value);
        $this->assertSame(['code' => 'over_threshold', 'changed_files' => 2, 'tracked_files' => 16, 'threshold_percent' => 10], $run->change_summary['full_reason']);
        $this->assertSame('2 of 16 files changed (13%), above the 10% threshold.', FullProcessingReason::sentence($run->change_summary['full_reason']));
        $this->assertSame(2, $run->files_changed);
        // A full run parses every file again and looks at every module.
        $this->assertSame(13, $run->files_analyzed);
        $this->assertSame(9, $run->modules_affected);
        $this->assertSame(11, $run->documents_regenerated + $run->documents_kept);
    }

    public function test_a_full_run_can_be_asked_for(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);

        $run = $this->process($repository, full: true);

        $this->assertSame('full', $run->mode->value);
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(13, $run->files_analyzed);
    }

    public function test_a_page_of_claude_is_kept_and_marked_stale_when_claude_cannot_write_it_again(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([self::AI_PAGE]));
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        $this->app->instance(TextGenerator::class, new NullTextGenerator);
        $newSha = $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $run = $this->process($repository);

        $page = Document::firstWhere('slug', 'src-support');
        $this->assertSame(Document::WRITER_AI, $page->writer);
        $this->assertSame(self::AI_PAGE, $page->markdown);
        $this->assertSame($firstRun->to_sha, $page->source_sha);
        $this->assertTrue($page->is_stale);
        $this->assertSame(
            "The module's code changed at commit ".substr($newSha, 0, 7).' (1 file); this page describes commit '.substr($firstRun->to_sha, 0, 7).'. '
            .'Claude is not available right now (AI is turned off), so the page keeps its previous text until a run with AI.',
            $page->stale_reason,
        );
        $this->assertSame(0, $run->documents_regenerated);
        $this->assertSame(1, Document::where('is_stale', true)->count());
        $this->assertSame(11, DocumentVersion::count());
    }

    public function test_a_page_of_claude_that_claude_fails_to_rewrite_keeps_its_text_and_says_why(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([self::AI_PAGE]));
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator(exception: new AiException(__(AiException::LIMIT_MESSAGE), retryable: true)));
        $newSha = $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $run = $this->process($repository);

        $page = Document::firstWhere('slug', 'src-support');
        $this->assertSame(self::AI_PAGE, $page->markdown);
        $this->assertSame($firstRun->to_sha, $page->source_sha);
        $this->assertSame(
            "The module's code changed at commit ".substr($newSha, 0, 7).' (1 file); this page describes commit '.substr($firstRun->to_sha, 0, 7).'. '
            .'Claude could not write it again: '.__(AiException::LIMIT_MESSAGE),
            $page->stale_reason,
        );
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(0, $run->documents_regenerated);
        $this->assertCount(1, $this->documentationRequests($generator));
    }

    public function test_the_overview_is_checked_when_enough_modules_changed(): void
    {
        config(['gitchronicle.incremental.overview_share' => 0.2]);
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $oneModule = $this->process($repository);

        // 1 changed module of 9 is below 20%: the overview is not even looked at.
        $this->assertSame(1, $oneModule->documents_regenerated);
        $this->pushToOrigin([
            'src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD."\n// iar\n",
            'tools/stats.py' => "def average(values):\n    return 0\n",
        ]);

        $twoModules = $this->process($repository);

        // 2 of 9 (22%) reach the share: the overview is checked, and its context (the symbols) changed.
        $this->assertSame($twoModules->to_sha, Document::firstWhere('slug', Document::OVERVIEW)->source_sha);
    }

    public function test_a_stale_page_is_written_again_at_the_next_run_even_without_a_new_commit(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([self::AI_PAGE]));
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        $this->app->instance(TextGenerator::class, new NullTextGenerator);
        $newSha = $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);
        $this->process($repository);
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([self::AI_PAGE."\nText nou.\n"]));

        $run = $this->process($repository);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(0, $run->files_changed);
        $this->assertCount(1, $this->documentationRequests($generator));
        $this->assertSame(1, $run->documents_regenerated);
        $this->assertDatabaseHas('documents', ['slug' => 'src-support', 'is_stale' => false, 'stale_reason' => null, 'source_sha' => $newSha]);
        $this->assertSame(12, DocumentVersion::count());
    }

    public function test_without_ai_a_stale_page_does_not_keep_a_run_at_the_same_commit_going(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([self::AI_PAGE]));
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        $this->app->instance(TextGenerator::class, new NullTextGenerator);
        $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);
        $this->process($repository);

        $run = $this->process($repository);

        $this->assertSame('skipped', $run->status->value);
        $this->assertSame(1, Document::where('is_stale', true)->count());
    }

    public function test_only_the_changed_files_are_indexed_again_for_the_assistant(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        $chunkIdsBefore = $this->chunkIdsByPath($repository);
        $this->pushToOrigin(
            write: ['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD, 'src/Support/Vat.php' => "<?php\n\nnamespace Shop\\Support;\n\nfinal class Vat\n{\n    public const RATE = 19;\n}\n"],
            delete: ['tools/stats.py'],
        );

        $this->process($repository);

        $chunkIdsAfter = $this->chunkIdsByPath($repository);
        $this->assertArrayNotHasKey('tools/stats.py', $chunkIdsAfter);
        $this->assertArrayHasKey('src/Support/Vat.php', $chunkIdsAfter);
        $this->assertNotSame($chunkIdsBefore['src/Support/Money.php'], $chunkIdsAfter['src/Support/Money.php']);
        // Every other file keeps exactly the chunks it had: they were not written again.
        $unchanged = array_diff_key($chunkIdsBefore, array_flip(['src/Support/Money.php', 'tools/stats.py']));
        $this->assertSame($unchanged, array_intersect_key($chunkIdsAfter, $unchanged));
        $this->assertTrue(CodeChunk::whereBelongsTo($repository)->where('symbol_name', 'Money.zero')->exists());
        // The search index follows the chunks: one row for each chunk, none for the deleted ones.
        $this->assertSame(
            CodeChunk::whereBelongsTo($repository)->orderBy('id')->pluck('id')->all(),
            \DB::table('code_chunks_fts')->where('repository_id', $repository->id)->orderBy('rowid')->pluck('rowid')->all(),
        );
    }

    public function test_after_a_failed_run_the_next_one_starts_from_the_last_commit_processed_to_the_end(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);
        $this->mock(CodeSearch::class)->shouldReceive('sync')->andThrow(new RuntimeException('Indexul nu a putut fi scris.'));

        $failedRun = rescue(fn () => $this->process($repository), fn () => $repository->processingRuns()->latest('id')->firstOrFail(), report: false);

        $this->assertSame('failed', $failedRun->fresh()->status->value);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'failed']);
        // The pages the failed run wrote stay on the site, and their versions belong to that run.
        $this->assertSame(1, DocumentVersion::where('processing_run_id', $failedRun->id)->count());
        $this->app->forgetInstance(CodeSearch::class);
        $newSha = $this->pushToOrigin(['src/Support/Vat.php' => "<?php\n\nnamespace Shop\\Support;\n\nfinal class Vat\n{\n    public const RATE = 19;\n}\n"]);

        $run = $this->process($repository);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame('incremental', $run->mode->value);
        $this->assertSame($firstRun->to_sha, $run->from_sha);
        $this->assertSame($newSha, $run->to_sha);
        // Both commits since the last success are in the change set: Money.php (failed run) and Vat.php.
        $this->assertSame(['added' => 1, 'modified' => 1, 'deleted' => 0], array_intersect_key($run->change_summary, array_flip(['added', 'modified', 'deleted'])));
        $this->assertTrue(CodeChunk::whereBelongsTo($repository)->where('symbol_name', 'Money.zero')->exists());
        $this->assertTrue(CodeChunk::whereBelongsTo($repository)->whereHas('repositoryFile', fn ($file) => $file->where('path', 'src/Support/Vat.php'))->exists());
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready', 'last_sha' => $newSha]);
    }

    public function test_a_rewritten_history_is_compared_by_content_and_a_lost_commit_makes_a_full_run(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $this->process($repository);
        // A force push: the only commit of the branch is replaced by another one with other content.
        $this->rewriteOriginHistory(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $run = $this->process($repository);

        // The old commit is still in the clone, so the two trees are compared: one file changed.
        $this->assertSame('incremental', $run->mode->value);
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(1, $run->files_changed);

        // The clone is lost and the history rewritten again: the last processed commit exists nowhere now.
        Storage::disk('local')->deleteDirectory("repositories/{$repository->id}");
        $this->rewriteOriginHistory(['tools/stats.py' => "def average(values):\n    return 0\n"]);

        $run = $this->process($repository);

        $this->assertSame('full', $run->mode->value);
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(['code' => 'missing_commit'], $run->change_summary['full_reason']);
        $this->assertSame(13, $run->files_analyzed);
    }

    public function test_a_broken_clone_is_cloned_again_and_the_update_stays_incremental(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        Storage::disk('local')->delete("repositories/{$repository->id}/.git/HEAD");
        $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);

        $run = $this->process($repository);

        $this->assertSame('incremental', $run->mode->value);
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame($firstRun->to_sha, $run->from_sha);
        $this->assertSame(1, $run->files_changed);
        Storage::disk('local')->assertExists("repositories/{$repository->id}/.git/HEAD");
    }

    public function test_a_root_file_refreshes_the_overview_and_a_file_outside_the_analysis_affects_no_module(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        Storage::disk('local')->put('origin/assets/logo.bin', "\0\1\2 binar");
        $binarySha = $this->pushToOrigin();

        $run = $this->process($repository);

        $this->assertSame(1, $run->files_changed);
        $this->assertSame(0, $run->modules_affected);
        $this->assertSame([], $run->change_summary['modules']);
        $this->assertSame([], $run->change_summary['project_files']);
        $this->assertSame(0, $run->documents_regenerated);
        $this->assertSame([$firstRun->to_sha], Document::distinct()->pluck('source_sha')->all());

        $this->pushToOrigin(['README.md' => "# Magazin\n\nUn magazin online mic, cu un README nou.\n"]);

        $run = $this->process($repository);

        $this->assertSame(['README.md'], $run->change_summary['project_files']);
        $this->assertSame($run->to_sha, Document::firstWhere('slug', Document::OVERVIEW)->source_sha);
        $this->assertNotSame($binarySha, $run->to_sha);
    }

    public function test_the_pages_of_an_old_commit_are_found_by_the_run_that_processed_it(): void
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $firstRun = $this->process($repository);
        $firstText = Document::firstWhere('slug', 'src-support')->markdown;
        $this->pushToOrigin(['src/Support/Money.php' => self::MONEY_WITH_A_NEW_METHOD]);
        $this->process($repository);
        $this->pushToOrigin(['tools/stats.py' => "def average(values):\n    return 0\n"]);
        $this->process($repository);

        $this->assertNotSame($firstText, Document::firstWhere('slug', 'src-support')->markdown);
        $this->get("/repositories/{$repository->id}/docs/src-support?sha={$firstRun->to_sha}")
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('time_travel.selected', $firstRun->to_sha)
                ->where('time_travel.version.markdown', $firstText)
                ->has('time_travel.commits', 3));
    }

    /**
     * Replace the origin's branch with one new commit that has the given changes (a force push):
     * the commit the clone had is no longer in the origin at all.
     *
     * @param  array<string, string>  $write
     */
    private function rewriteOriginHistory(array $write): void
    {
        foreach ($write as $path => $contents) {
            Storage::disk('local')->put("origin/{$path}", $contents);
        }

        $this->git($this->originPath(), ['add', '--all']);
        $this->git($this->originPath(), ['commit', '--amend', '--quiet', '-m', 'Istorie rescrisă']);
        // GitHub never serves a commit nobody points to; a clone of a local folder copies every object,
        // so the replaced commit is removed from the origin for real.
        $this->git($this->originPath(), ['reflog', 'expire', '--expire=now', '--all']);
        $this->git($this->originPath(), ['gc', '--prune=now', '--quiet']);
    }

    /**
     * The requests the documentation writer sent (the diagrams step asks Claude for the flows too).
     *
     * @return list<array{system: string, user: string}>
     */
    private function documentationRequests(FakeTextGenerator $generator): array
    {
        return array_values(array_filter($generator->requests, fn (array $request): bool => $request['system'] === AiDocumentationWriter::systemPrompt()));
    }

    /**
     * The ids of the search chunks of every file, by path.
     *
     * @return array<string, list<int>>
     */
    private function chunkIdsByPath(Repository $repository): array
    {
        return CodeChunk::whereBelongsTo($repository)
            ->with('repositoryFile:id,path')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CodeChunk $chunk): string => $chunk->repositoryFile->path)
            ->map(fn ($chunks): array => $chunks->pluck('id')->all())
            ->all();
    }
}
