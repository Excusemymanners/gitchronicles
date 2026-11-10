<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingTrigger;
use App\Jobs\IndexCode;
use App\Jobs\ProcessRepository;
use App\Models\CodeChunk;
use App\Models\CodeSymbol;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Search\CodeIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

class IndexCodeTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    public function test_cuts_the_analyzed_files_of_the_clone_into_searchable_chunks(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        Storage::disk('local')->put("repositories/{$repository->id}/app/Greeter.php", "<?php\n\nfunction greet(): string\n{\n    return 'Salut';\n}\n");
        $file = RepositoryFile::factory()->for($repository)->create(['path' => 'app/Greeter.php']);
        CodeSymbol::factory()->for($file, 'repositoryFile')->create(['kind' => 'function', 'name' => 'greet', 'start_line' => 3, 'end_line' => 6]);
        $run = ProcessingRun::factory()->for($repository)->create();

        (new IndexCode($repository, $run))->handle(app(CodeIndexer::class));

        $this->assertSame(
            [[1, 2, null], [3, 6, 'greet']],
            $repository->codeChunks()->orderBy('start_line')->get()
                ->map(fn (CodeChunk $chunk): array => [$chunk->start_line, $chunk->end_line, $chunk->symbol_name])
                ->all(),
        );
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
    }

    public function test_the_pipeline_indexes_the_code_before_the_repository_is_ready(): void
    {
        $this->skipUnlessAnalyzerIsInstalled();
        $originPath = Storage::disk('local')->path('origin');
        Storage::disk('local')->put('origin/README.md', "# Proiect de test\n");
        Storage::disk('local')->put('origin/app.php', "<?php\n\nfunction greet(): string\n{\n    return 'Salut';\n}\n");
        $this->commitEverything($originPath);
        $repository = Repository::factory()->create(['url' => $originPath]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);
        $this->assertSame(['README.md', 'app.php'], CodeChunk::query()
            ->whereBelongsTo($repository)
            ->with('repositoryFile')
            ->get()
            ->map(fn (CodeChunk $chunk): string => $chunk->repositoryFile->path)
            ->unique()
            ->sort()
            ->values()
            ->all());
    }
}
