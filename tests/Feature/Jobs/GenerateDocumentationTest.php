<?php

namespace Tests\Feature\Jobs;

use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Documentation\AiDocumentationWriter;
use App\Documentation\ContextBuilder;
use App\Documentation\DocumentationGenerator;
use App\Enums\ProcessingTrigger;
use App\Jobs\GenerateDocumentation;
use App\Jobs\ProcessRepository;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAnalyzedRepositories;
use Tests\Concerns\RunsGit;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The documentation step of the pipeline: which pages it writes, with Claude (FakeTextGenerator, never
 * the CLI or the API) or with the template, what it skips, what it keeps, and what the run records.
 */
class GenerateDocumentationTest extends TestCase
{
    use CreatesAnalyzedRepositories;
    use RefreshDatabase;
    use RunsGit;

    /**
     * An answer that has every heading of a module page and of the overview, so it is accepted for every page.
     */
    private const AI_PAGE = "# The page\n\n## Role\n\nText.\n\n## Main components\n\n## How it works\n\n## Dependencies\n\n## Files\n\n"
        ."## What the project does\n\n## Technologies\n\n## Architecture\n\n## Modules\n\n## How to run it\n";

    public function test_pipeline_documents_the_fixture_project_with_the_template_when_ai_is_off(): void
    {
        $this->skipUnlessAnalyzerIsInstalled();
        $originPath = Storage::disk('local')->path('origin');
        File::copyDirectory(base_path('tests/Fixtures/analyzer-project'), $originPath);
        $this->commitEverything($originPath);
        $repository = Repository::factory()->create(['url' => $originPath, 'owner' => 'acme', 'name' => 'magazin']);
        $this->travelTo('2026-11-10 12:00:00');

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready', 'documented_at' => '2026-11-10 12:00:00']);
        $this->assertSame([
            'frontend', 'frontend-components', 'frontend-lib', 'overview', 'radacina', 'readme',
            'src-http', 'src-models', 'src-support', 'tools', 'tools-cli',
        ], Document::whereBelongsTo($repository)->orderBy('slug')->pluck('slug')->all());
        $this->assertSame([Document::WRITER_TEMPLATE], Document::whereBelongsTo($repository)->distinct()->pluck('writer')->all());
        $this->assertDatabaseHas('processing_runs', [
            'repository_id' => $repository->id,
            'status' => 'succeeded',
            'documents_regenerated' => 11,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd_equivalent' => null,
        ]);
        $this->assertSame(11, DocumentVersion::count());
        $headSha = $this->git($originPath, ['rev-parse', 'HEAD']);
        $controllerPage = Document::whereBelongsTo($repository)->firstWhere('slug', 'src-http');
        $this->assertSame('src/Http', $controllerPage->title);
        $this->assertSame($headSha, $controllerPage->source_sha);
        $this->assertStringContainsString("https://github.com/acme/magazin/blob/{$headSha}/src/Http/ProductController.php#L10-L19", $controllerPage->markdown);
    }

    public function test_claude_writes_every_page_and_the_run_records_the_tokens(): void
    {
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([self::AI_PAGE]));
        ['repository' => $repository] = $this->shopRepository();
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $run);

        $generator->assertSentCount(5);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'documenting']);
        $this->assertNotNull($repository->fresh()->documented_at);
        $this->assertDatabaseHas('processing_runs', ['id' => $run->id, 'documents_regenerated' => 5, 'input_tokens' => 500, 'output_tokens' => 250]);
        $this->assertDatabaseHas('documents', [
            'repository_id' => $repository->id,
            'slug' => 'src-models',
            'writer' => 'ai',
            'model' => 'claude-opus-5-5',
            'ai_driver' => 'fake',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'is_stale' => false,
            'source_sha' => str_repeat('a', 40),
        ]);
        $this->assertSame(
            ['radacina', 'src-http', 'src-models', 'overview', 'readme'],
            array_map(fn (array $request): string => $this->pageOf($request['user']), $generator->requests),
        );
        $this->assertSame([AiDocumentationWriter::systemPrompt()], array_values(array_unique(array_column($generator->requests, 'system'))));
        $this->assertStringContainsString('File link base: https://github.com/acme/magazin/blob/'.str_repeat('a', 40).'/', $generator->requests[2]['user']);
        $this->assertStringContainsString('3| class Product', $generator->requests[2]['user']);
    }

    public function test_the_run_adds_up_what_the_calls_would_cost_on_the_api(): void
    {
        $this->app->instance(TextGenerator::class, $this->generatorAnswering(fn (): AiResult => new AiResult(self::AI_PAGE, 1000, 200, 0.0125, 'claude-opus-5-5', 'claude-code')));
        ['repository' => $repository] = $this->shopRepository();
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $run);

        $this->assertSame('0.062500', $run->fresh()->cost_usd_equivalent);
        $this->assertDatabaseHas('documents', ['slug' => Document::README, 'cost_usd_equivalent' => 0.0125, 'ai_driver' => 'claude-code']);
    }

    public function test_pages_whose_input_did_not_change_are_not_written_again(): void
    {
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([self::AI_PAGE]));
        ['repository' => $repository] = $this->shopRepository();
        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());
        $secondRun = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $secondRun);

        $generator->assertSentCount(5);
        $this->assertDatabaseHas('processing_runs', ['id' => $secondRun->id, 'documents_regenerated' => 0, 'input_tokens' => 0]);
        $this->assertSame(5, DocumentVersion::count());
    }

    public function test_a_changed_module_is_written_again_and_gets_a_new_version_when_its_text_changes(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([...array_fill(0, 5, self::AI_PAGE), self::AI_PAGE."\nNew text.\n"]));
        ['repository' => $repository] = $this->shopRepository();
        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());
        Storage::disk('local')->put("repositories/{$repository->id}/src/Models/Category.php", "<?php\n\nclass Category extends Product\n{\n}\n");
        $secondRun = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $secondRun);

        $this->assertDatabaseHas('processing_runs', ['id' => $secondRun->id, 'documents_regenerated' => 1]);
        $changedPage = Document::whereBelongsTo($repository)->firstWhere('slug', 'src-models');
        $this->assertSame([self::AI_PAGE, self::AI_PAGE."\nNew text.\n"], $changedPage->versions()->pluck('markdown')->all());
        $this->assertSame(1, Document::whereBelongsTo($repository)->firstWhere('slug', 'src-http')->versions()->count());
    }

    public function test_a_page_written_again_with_the_same_text_gets_no_new_version(): void
    {
        ['repository' => $repository] = $this->shopRepository();
        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());
        Storage::disk('local')->put("repositories/{$repository->id}/src/Models/Category.php", "<?php\n\nclass Category extends Product\n{\n}\n");
        $secondRun = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $secondRun);

        $this->assertDatabaseHas('processing_runs', ['id' => $secondRun->id, 'documents_regenerated' => 1]);
        $this->assertSame(1, Document::whereBelongsTo($repository)->firstWhere('slug', 'src-models')->versions()->count());
    }

    public function test_the_template_writes_a_page_claude_could_not_write_and_marks_it_stale(): void
    {
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator(exception: new AiException('Claude refused to write this text.')));
        ['repository' => $repository] = $this->shopRepository();
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $run);

        $generator->assertSentCount(5);
        $this->assertSame(5, Document::whereBelongsTo($repository)->where('is_stale', true)->where('writer', 'template')->count());
        $this->assertSame(
            'Claude could not write this page: Claude refused to write this text. Until the next run, the page is generated from the code analysis.',
            Document::whereBelongsTo($repository)->firstWhere('slug', 'src-models')->stale_reason,
        );
        $this->assertStringContainsString('## Main components', Document::whereBelongsTo($repository)->firstWhere('slug', 'src-models')->markdown);
        $this->assertDatabaseHas('processing_runs', ['id' => $run->id, 'documents_regenerated' => 5]);
    }

    public function test_an_error_that_will_repeat_pauses_claude_for_the_rest_of_the_run(): void
    {
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator(exception: new AiException(__(AiException::LIMIT_MESSAGE), retryable: true)));
        ['repository' => $repository] = $this->shopRepository();

        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());

        $generator->assertSentCount(1);
        $this->assertSame(5, Document::whereBelongsTo($repository)->where('is_stale', true)->count());
        $this->assertStringContainsString(__(AiException::LIMIT_MESSAGE), Document::whereBelongsTo($repository)->firstWhere('slug', Document::README)->stale_reason);
    }

    public function test_an_answer_that_is_not_the_requested_page_is_replaced_by_the_template_and_still_counted(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator(['I cannot write the page.']));
        ['repository' => $repository] = $this->shopRepository();
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $run);

        $this->assertDatabaseHas('documents', ['slug' => 'src-models', 'writer' => 'template', 'is_stale' => true, 'input_tokens' => 0]);
        $this->assertDatabaseHas('processing_runs', ['id' => $run->id, 'input_tokens' => 500, 'output_tokens' => 250]);
    }

    public function test_the_content_language_is_part_of_the_input_hash_so_a_page_in_another_language_is_written_again(): void
    {
        ['repository' => $repository] = $this->shopRepository();
        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());
        $page = Document::whereBelongsTo($repository)->firstWhere('slug', 'src-models');
        $context = app(ContextBuilder::class)->module($page->module);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
        $this->assertSame(hash('sha256', json_encode([...$context->toArray(), 'content_language' => 'en'], $flags)), $page->input_hash);
        // A page written in Romanian before the language was part of the hash: same facts, other hash.
        $page->update(['markdown' => "# Modulul `src/Models`\n\n## Rol\n\nText vechi.\n", 'input_hash' => hash('sha256', json_encode($context->toArray(), $flags))]);
        $secondRun = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $secondRun);

        $this->assertDatabaseHas('processing_runs', ['id' => $secondRun->id, 'documents_regenerated' => 1]);
        $this->assertStringStartsWith("# Module `src/Models`\n\n## Role\n", $page->fresh()->markdown);
        $this->assertSame($context->inputHash(), $page->fresh()->input_hash);
    }

    public function test_a_page_of_claude_in_another_language_is_kept_without_ai_and_says_its_input_changed_at_the_same_commit(): void
    {
        ['repository' => $repository] = $this->shopRepository();
        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());
        $page = Document::whereBelongsTo($repository)->firstWhere('slug', 'src-models');
        $page->update(['writer' => Document::WRITER_AI, 'markdown' => "# Modulul `src/Models`\n", 'input_hash' => str_repeat('0', 64)]);

        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());

        $this->assertSame("# Modulul `src/Models`\n", $page->fresh()->markdown);
        $this->assertSame(
            'The code is still at commit '.substr(str_repeat('a', 40), 0, 7).', but what this page is written from changed (for example the content language). '
            .'Claude is not available right now (AI is turned off), so the page keeps its previous text until a run with AI.',
            $page->fresh()->stale_reason,
        );
    }

    public function test_a_page_written_by_claude_is_kept_when_ai_is_turned_off(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([self::AI_PAGE]));
        ['repository' => $repository] = $this->shopRepository();
        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());
        $this->app->instance(TextGenerator::class, new NullTextGenerator);
        $secondRun = ProcessingRun::factory()->for($repository)->create();

        $this->runJob($repository, $secondRun);

        $this->assertDatabaseHas('processing_runs', ['id' => $secondRun->id, 'documents_regenerated' => 0]);
        $this->assertSame(5, Document::whereBelongsTo($repository)->where('writer', 'ai')->count());
    }

    public function test_a_page_of_the_template_is_written_again_by_claude_once_ai_is_on(): void
    {
        $this->app->instance(TextGenerator::class, new NullTextGenerator);
        ['repository' => $repository] = $this->shopRepository();
        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());
        $this->assertSame(0, Document::whereBelongsTo($repository)->where('is_stale', true)->count());
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([self::AI_PAGE]));

        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());

        $this->assertSame(5, Document::whereBelongsTo($repository)->where('writer', 'ai')->count());
        $this->assertSame(10, DocumentVersion::count());
    }

    public function test_modules_over_the_page_limit_are_listed_on_the_overview_without_a_page(): void
    {
        config(['gitchronicle.docs.max_module_pages' => 1]);
        ['repository' => $repository] = $this->shopRepository();

        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());

        $this->assertSame(['overview', 'readme', 'src-models'], Document::whereBelongsTo($repository)->orderBy('slug')->pluck('slug')->all());
        $overview = Document::whereBelongsTo($repository)->firstWhere('slug', Document::OVERVIEW)->markdown;
        $this->assertStringContainsString('| `/` | 2 | 13 | no page: over the limit: only the biggest module has a page', $overview);
        $this->assertStringContainsString('| `src/Http` | 1 | 8 | no page: over the limit: only the biggest module has a page', $overview);
    }

    public function test_removes_the_pages_of_modules_that_no_longer_exist(): void
    {
        ['repository' => $repository] = $this->shopRepository();
        $oldPage = Document::factory()->for($repository)->create(['slug' => 'src-old', 'title' => 'src/Old']);
        $oldVersion = DocumentVersion::factory()->for($oldPage)->create();

        $this->runJob($repository, ProcessingRun::factory()->for($repository)->create());

        $this->assertModelMissing($oldPage);
        $this->assertModelMissing($oldVersion);
    }

    public function test_a_job_out_of_time_hands_the_remaining_pages_to_a_new_job_of_the_same_run(): void
    {
        $this->app->instance(TextGenerator::class, $this->generatorAnswering(function (): AiResult {
            $this->travel(GenerateDocumentation::TIME_BUDGET_SECONDS + 1)->seconds();

            return new AiResult(self::AI_PAGE, 10, 5, null, 'claude-opus-5-5', 'fake');
        }));
        ['repository' => $repository] = $this->shopRepository();
        $run = ProcessingRun::factory()->for($repository)->create();
        $documentationJobs = 0;
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$documentationJobs): void {
            $documentationJobs += $event->job->resolveName() === GenerateDocumentation::class ? 1 : 0;
        });

        Bus::chain([new GenerateDocumentation($repository, $run)])->dispatch();

        $this->assertSame(5, $documentationJobs);
        $this->assertSame(5, Document::whereBelongsTo($repository)->where('writer', 'ai')->count());
        $this->assertDatabaseHas('processing_runs', ['id' => $run->id, 'documents_regenerated' => 5, 'input_tokens' => 50]);
        $this->assertNotNull($repository->fresh()->documented_at);
    }

    /**
     * Run the documentation step once, outside the queue (any continuation job is not run).
     */
    private function runJob(Repository $repository, ProcessingRun $run): void
    {
        (new GenerateDocumentation($repository, $run))->handle(app(DocumentationGenerator::class));
    }

    /**
     * The page a request was for: the module page's path, or the page type of the overview and the README.
     */
    private function pageOf(string $user): string
    {
        if (preg_match('/^Module: (\S+)/m', $user, $module) === 1) {
            return $module[1] === '/' ? 'radacina' : str_replace('/', '-', strtolower($module[1]));
        }

        return preg_match('/\APage type: (\w+)/', $user, $type) === 1 ? $type[1] : '?';
    }

    /**
     * A TextGenerator whose every answer comes from the callback.
     *
     * @param  callable(): AiResult  $answer
     */
    private function generatorAnswering(callable $answer): TextGenerator
    {
        return new class($answer) implements TextGenerator
        {
            /**
             * @param  callable(): AiResult  $answer
             */
            public function __construct(private $answer) {}

            public function generate(string $system, string $user): AiResult
            {
                return ($this->answer)();
            }
        };
    }
}
