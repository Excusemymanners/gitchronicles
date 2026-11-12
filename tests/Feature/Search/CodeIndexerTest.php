<?php

namespace Tests\Feature\Search;

use App\Analysis\RepositoryAnalyzer;
use App\Models\CodeChunk;
use App\Models\Repository;
use App\Search\CodeIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * The indexing of a real analysis: the Faza 5 fixture project (tests/Fixtures/analyzer-project)
 * is committed into the clone folder, analyzed with the real analyzer, then cut into chunks.
 * Every test is skipped when the analyzer is not installed (analyzer/.venv).
 */
class CodeIndexerTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessAnalyzerIsInstalled();
    }

    public function test_cuts_each_function_and_method_into_its_own_chunk_and_the_other_lines_into_windows(): void
    {
        $repository = $this->analyzedFixtureProject();

        $summary = app(CodeIndexer::class)->index($repository);

        $this->assertSame(['files_indexed' => 13, 'chunks_total' => 27, 'files_left_out' => 0], $summary);
        $this->assertSame([
            [1, 11, null],
            [12, 18, 'ProductController.show'],
        ], $this->chunksOf($repository, 'src/Http/ProductController.php'));
        $this->assertSame([
            [1, 1, 'Stats'],
            [2, 3, 'Stats.__init__'],
            [5, 6, 'Stats.add'],
            [9, 10, 'average'],
        ], $this->chunksOf($repository, 'tools/stats.py'));
        $this->assertSame([
            [1, 2, null],
            [3, 5, 'Props'],
            [7, 9, 'ProductCard'],
        ], $this->chunksOf($repository, 'frontend/components/product-card.tsx'));
        $this->assertSame([[1, 7, null]], $this->chunksOf($repository, 'README.md'));
        $this->assertSame(
            "    public function show(Request \$request): string\n    {\n        \$product = new Product((int) \$request->query('price'));\n        \$category = new Category('Cărți');\n\n        return \$category->name.': '.\$product->price().' / '.Money::format(0);\n    }",
            CodeChunk::firstWhere('symbol_name', 'ProductController.show')->content,
        );
    }

    public function test_writes_one_search_index_row_for_every_chunk(): void
    {
        $repository = $this->analyzedFixtureProject();

        app(CodeIndexer::class)->index($repository);

        $this->assertSame(27, $this->indexRowsOf($repository));
        $this->assertSame(
            'src/Http/Product Controller.php',
            DB::table('code_chunks_fts')->where('rowid', CodeChunk::firstWhere('symbol_name', 'ProductController.show')->id)->value('path'),
        );
    }

    public function test_skips_drawings_and_generated_files_with_very_long_lines(): void
    {
        $repository = Repository::factory()->ready()->create();
        $clonePath = $this->copyFixtureProject($repository);
        File::put("{$clonePath}/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>');
        File::put("{$clonePath}/bundle.js", 'var a='.str_repeat('1+', 1500).'1;');
        $this->commitEverything($clonePath);
        app(RepositoryAnalyzer::class)->analyze($repository);

        $summary = app(CodeIndexer::class)->index($repository);

        $this->assertSame(15, $repository->files()->count());
        $this->assertSame(13, $summary['files_indexed']);
        $this->assertSame([], $this->chunksOf($repository, 'logo.svg'));
        $this->assertSame([], $this->chunksOf($repository, 'bundle.js'));
    }

    public function test_indexing_again_replaces_the_chunks_and_the_index_of_this_repository_only(): void
    {
        $otherChunk = CodeChunk::factory()->create();
        $repository = $this->analyzedFixtureProject();
        app(CodeIndexer::class)->index($repository);
        $firstIds = $repository->codeChunks()->pluck('id');

        app(CodeIndexer::class)->index($repository);

        $this->assertSame(27, $repository->codeChunks()->count());
        $this->assertSame(27, $this->indexRowsOf($repository));
        $this->assertSame([], $repository->codeChunks()->whereIn('id', $firstIds)->pluck('id')->all());
        $this->assertModelExists($otherChunk);
    }

    public function test_a_new_analysis_followed_by_indexing_leaves_no_index_rows_of_deleted_chunks(): void
    {
        $repository = $this->analyzedFixtureProject();
        app(CodeIndexer::class)->index($repository);

        app(RepositoryAnalyzer::class)->analyze($repository);
        app(CodeIndexer::class)->index($repository);

        $this->assertSame(27, $this->indexRowsOf($repository));
        $this->assertSame(0, DB::table('code_chunks_fts')->whereNotIn('rowid', CodeChunk::query()->select('id'))->count());
    }

    /**
     * A ready repository whose clone holds the fixture project, already analyzed.
     */
    private function analyzedFixtureProject(): Repository
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitEverything($this->copyFixtureProject($repository));
        app(RepositoryAnalyzer::class)->analyze($repository);

        return $repository;
    }

    /**
     * Copy the fixture project into the repository's clone folder and return that folder.
     */
    private function copyFixtureProject(Repository $repository): string
    {
        File::copyDirectory(base_path('tests/Fixtures/analyzer-project'), $repository->clonePath());

        return $repository->clonePath();
    }

    /**
     * The chunks of one file as [start line, end line, symbol name], in the order of the lines.
     *
     * @return list<array{int, int, string|null}>
     */
    private function chunksOf(Repository $repository, string $path): array
    {
        return $repository->codeChunks()
            ->whereRelation('repositoryFile', 'path', $path)
            ->orderBy('start_line')
            ->get()
            ->map(fn (CodeChunk $chunk): array => [$chunk->start_line, $chunk->end_line, $chunk->symbol_name])
            ->all();
    }

    /**
     * How many rows the search index has for the repository.
     */
    private function indexRowsOf(Repository $repository): int
    {
        return DB::table('code_chunks_fts')->where('repository_id', $repository->id)->count();
    }

    public function test_files_over_the_chunk_limit_are_left_out_and_counted(): void
    {
        config(['gitchronicle.search.max_chunks' => 10]);
        $repository = $this->analyzedFixtureProject();

        $summary = app(CodeIndexer::class)->index($repository);

        $this->assertLessThanOrEqual(10, $summary['chunks_total']);
        $this->assertGreaterThan(0, $summary['files_left_out']);
        $this->assertSame(13, $summary['files_indexed'] + $summary['files_left_out']);
    }

    public function test_a_file_inside_a_folder_that_is_a_link_out_of_the_clone_is_not_indexed(): void
    {
        $repository = $this->analyzedFixtureProject();
        $outside = sys_get_temp_dir().'/gc-outside-'.bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents("{$outside}/Secret.php", "<?php\nfunction secret() { return 'x'; }\n");
        $file = $repository->files()->where('path', 'like', 'src/%')->first();
        $folder = dirname($repository->clonePath().'/'.$file->path);
        File::deleteDirectory($folder);
        symlink($outside, $folder);
        File::put("{$outside}/".basename($file->path), "<?php\nfunction leaked() { return 'secret'; }\n");

        app(CodeIndexer::class)->index($repository);

        $this->assertFalse($repository->codeChunks()->where('content', 'like', '%leaked%')->exists());
        File::deleteDirectory($outside);
    }
}
