<?php

namespace Tests\Feature\Diagrams;

use App\Ai\AiException;
use App\Ai\TextGenerator;
use App\Diagrams\FlowDiagramWriter;
use App\Models\Diagram;
use App\Models\Module;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * „Cum funcționează”: what Claude receives, which answers are saved, and what happens without AI.
 * Claude is never called: FakeTextGenerator answers instead (or AI is off, as in every test).
 */
class FlowDiagramWriterTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = "<?php\n\nuse App\\Http\\Controllers\\HomeController;\n\nRoute::get('/', HomeController::class);\n";

    private const CONTROLLER = "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass HomeController\n{\n    public function __invoke()\n    {\n        return view('home');\n    }\n}\n";

    public function test_sends_the_entry_files_with_numbered_lines_and_the_module_summaries(): void
    {
        $repository = $this->repositoryWithCode();
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([$this->answer()]));

        app(FlowDiagramWriter::class)->refresh($repository);

        $generator->assertSentCount(1);
        $context = $generator->requests[0]['user'];
        $this->assertStringContainsString("### routes/web.php\n1| <?php\n2| \n3| use App\\Http\\Controllers\\HomeController;", $context);
        $this->assertStringContainsString('### app/Http/Controllers/HomeController.php', $context);
        $this->assertStringContainsString('- app/Http: 1 fișiere, 12 linii', $context);
        $this->assertStringContainsString('flowchart TD', $generator->requests[0]['system']);
    }

    public function test_saves_the_valid_flows_for_the_current_commit_with_what_the_call_used(): void
    {
        $repository = $this->repositoryWithCode();
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([$this->answer()]));

        $result = app(FlowDiagramWriter::class)->refresh($repository);

        $this->assertSame(100, $result->inputTokens);
        $flow = $repository->diagrams()->sole();
        $this->assertSame('flow', $flow->type->value);
        $this->assertSame('pagina-principala', $flow->key);
        $this->assertSame($repository->last_sha, $flow->source_sha);
        $this->assertSame("flowchart TD\n  A[\"GET /\"] --> B[\"HomeController\"]\n", $flow->mermaid);
        $this->assertSame('Pagina principală', $flow->meta['title']);
        $this->assertSame([['path' => 'routes/web.php', 'start_line' => 5, 'end_line' => 5]], $flow->meta['sources']);
        $this->assertSame(['claude-opus-5-5', 100, 50], [$flow->meta['model'], $flow->meta['input_tokens'], $flow->meta['output_tokens']]);
    }

    public function test_does_not_ask_claude_again_when_the_context_has_not_changed(): void
    {
        $repository = $this->repositoryWithCode();
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([$this->answer()]));
        app(FlowDiagramWriter::class)->refresh($repository);
        $repository->update(['last_sha' => str_repeat('b', 40)]);

        $result = app(FlowDiagramWriter::class)->refresh($repository);

        $this->assertNull($result);
        $generator->assertSentCount(1);
        $this->assertSame(str_repeat('b', 40), $repository->diagrams()->sole()->source_sha);
    }

    public function test_drops_an_unsafe_answer_and_logs_why(): void
    {
        $repository = $this->repositoryWithCode();
        $unsafe = $this->answer("flowchart TD\n  A[\"GET /\"]\n  click A \"https://example.com\"");
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([$unsafe]));
        Log::spy();

        app(FlowDiagramWriter::class)->refresh($repository);

        $this->assertSame(0, $repository->diagrams()->count());
        Log::shouldHaveReceived('warning')->with('Some flows written by Claude were rejected.', ['repository_id' => $repository->id, 'reasons' => ['flow 1: forbidden Mermaid syntax (click)']]);
    }

    public function test_without_ai_the_flows_of_an_older_commit_are_deleted(): void
    {
        $repository = $this->repositoryWithCode();
        $olderFlow = Diagram::factory()->flow()->for($repository)->create(['source_sha' => str_repeat('a', 40)]);

        $result = app(FlowDiagramWriter::class)->refresh($repository);

        $this->assertNull($result);
        $this->assertModelMissing($olderFlow);
    }

    public function test_when_claude_fails_the_flows_of_the_same_commit_are_kept(): void
    {
        $repository = $this->repositoryWithCode();
        $currentFlow = Diagram::factory()->flow()->for($repository)->create(['source_sha' => $repository->last_sha]);
        $this->app->instance(TextGenerator::class, new FakeTextGenerator(exception: new AiException(AiException::LIMIT_MESSAGE, retryable: true)));

        $result = app(FlowDiagramWriter::class)->refresh($repository);

        $this->assertNull($result);
        $this->assertModelExists($currentFlow);
    }

    public function test_a_repository_without_code_is_not_sent_to_claude(): void
    {
        $repository = Repository::factory()->ready()->create();
        $module = Module::factory()->for($repository)->create(['path' => '/']);
        RepositoryFile::factory()->for($repository)->for($module)->create(['path' => 'README.md', 'language' => 'Markdown']);
        $this->app->instance(TextGenerator::class, $generator = new FakeTextGenerator([$this->answer()]));

        app(FlowDiagramWriter::class)->refresh($repository);

        $generator->assertSentCount(0);
    }

    /**
     * A repository with a routes file and a controller, in the clone and in the analysis tables.
     */
    private function repositoryWithCode(): Repository
    {
        $repository = Repository::factory()->ready()->create();
        $routes = Module::factory()->for($repository)->create(['path' => 'routes', 'file_count' => 1, 'line_count' => 5]);
        $http = Module::factory()->for($repository)->create(['path' => 'app/Http', 'file_count' => 1, 'line_count' => 12]);

        foreach ([[$routes, 'routes/web.php', self::ROUTES], [$http, 'app/Http/Controllers/HomeController.php', self::CONTROLLER]] as [$module, $path, $contents]) {
            File::ensureDirectoryExists(dirname("{$repository->clonePath()}/{$path}"));
            File::put("{$repository->clonePath()}/{$path}", $contents);
            RepositoryFile::factory()->for($repository)->for($module)->create(['path' => $path, 'lines' => substr_count($contents, "\n")]);
        }

        return $repository;
    }

    /**
     * Claude's answer with one flow about the home page.
     */
    private function answer(string $mermaid = "flowchart TD\n  A[\"GET /\"] --> B[\"HomeController\"]"): string
    {
        return json_encode(['flows' => [[
            'title' => 'Pagina principală',
            'explanation' => 'Ruta / trimite cererea la HomeController, care întoarce pagina home.',
            'mermaid' => $mermaid,
            'sources' => [['path' => 'routes/web.php', 'start_line' => 5, 'end_line' => 5]],
        ]]], JSON_UNESCAPED_UNICODE);
    }
}
