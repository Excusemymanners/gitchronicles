<?php

namespace Tests\Feature\Jobs;

use App\Ai\TextGenerator;
use App\Diagrams\ArchitectureDiagramBuilder;
use App\Diagrams\DiagramHistory;
use App\Diagrams\FlowDiagramWriter;
use App\Enums\ProcessingTrigger;
use App\Jobs\AnalyzeRepository;
use App\Jobs\BuildDiagrams;
use App\Jobs\CloneRepository;
use App\Jobs\FinishProcessing;
use App\Jobs\GenerateDocumentation;
use App\Jobs\ImportCommits;
use App\Jobs\IndexCode;
use App\Jobs\ProcessRepository;
use App\Models\Diagram;
use App\Models\Module;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The diagrams step of the pipeline: it saves the architecture diagrams for the processed commit,
 * replaces them on every run, works without AI and without documentation, and counts Claude's tokens.
 */
class BuildDiagramsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pipeline_draws_the_diagrams_after_the_analysis(): void
    {
        Bus::fake();
        $repository = Repository::factory()->create();

        (new ProcessRepository($repository, ProcessingTrigger::Manual))->handle();

        Bus::assertChained([CloneRepository::class, AnalyzeRepository::class, ImportCommits::class, GenerateDocumentation::class, BuildDiagrams::class, IndexCode::class, FinishProcessing::class]);
    }

    public function test_saves_the_architecture_diagrams_for_the_processed_commit(): void
    {
        $repository = $this->analyzedRepository(['app/Http', 'app/Models']);
        $run = ProcessingRun::factory()->for($repository)->create();

        $this->handle(new BuildDiagrams($repository, $run));

        $this->assertSame(['component', 'layers'], $repository->diagrams()->orderBy('type')->pluck('type')->map->value->all());
        $this->assertSame([$repository->last_sha], $repository->diagrams()->distinct()->pluck('source_sha')->all());
        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $run->fresh()->only(['input_tokens', 'output_tokens']));
    }

    public function test_running_again_replaces_the_diagrams_deletes_the_views_without_data_and_keeps_the_flows(): void
    {
        $repository = $this->analyzedRepository(['routes', 'app/Models']);
        $run = ProcessingRun::factory()->for($repository)->create();
        $this->handle(new BuildDiagrams($repository, $run));
        $flow = Diagram::factory()->flow()->for($repository)->create(['source_sha' => $repository->last_sha]);
        Module::query()->where('path', 'app/Models')->delete();

        $this->handle(new BuildDiagrams($repository, $run));

        $this->assertSame(['flow', 'layers'], $repository->diagrams()->orderBy('type')->pluck('type')->map->value->all());
        $this->assertStringNotContainsString('app/Models', $repository->diagrams()->firstWhere('type', 'layers')->mermaid);
        $this->assertModelExists($flow);
    }

    public function test_adds_the_tokens_of_the_flows_to_the_processing_run(): void
    {
        $repository = $this->analyzedRepository(['routes']);
        $run = ProcessingRun::factory()->for($repository)->create(['input_tokens' => 1000, 'output_tokens' => 200]);
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([json_encode(['flows' => [[
            'title' => 'Rutele',
            'explanation' => 'Fișierul de rute descrie paginile aplicației.',
            'mermaid' => "flowchart TD\n  A[\"routes/File.php\"]",
            'sources' => [['path' => 'routes/File.php', 'start_line' => 1, 'end_line' => 1]],
        ]]])]));

        $this->handle(new BuildDiagrams($repository, $run));

        $this->assertSame(['input_tokens' => 1100, 'output_tokens' => 250], $run->fresh()->only(['input_tokens', 'output_tokens']));
        $this->assertDatabaseHas('diagrams', ['repository_id' => $repository->id, 'type' => 'flow', 'key' => 'rutele']);
    }

    /**
     * Run the job the way the queue does, with its dependencies from the container.
     */
    private function handle(BuildDiagrams $job): void
    {
        $job->handle(app(ArchitectureDiagramBuilder::class), app(FlowDiagramWriter::class), app(DiagramHistory::class));
    }

    /**
     * A ready repository whose analysis found one PHP file in each of these modules (and in the clone).
     *
     * @param  list<string>  $modulePaths
     */
    private function analyzedRepository(array $modulePaths): Repository
    {
        $repository = Repository::factory()->ready()->analyzed()->create();

        foreach ($modulePaths as $path) {
            $module = Module::factory()->for($repository)->create(['path' => $path, 'line_count' => 3]);
            RepositoryFile::factory()->for($repository)->for($module)->create(['path' => "{$path}/File.php", 'lines' => 3]);
            File::ensureDirectoryExists("{$repository->clonePath()}/{$path}");
            File::put("{$repository->clonePath()}/{$path}/File.php", "<?php\n\n// {$path}\n");
        }

        return $repository;
    }
}
