<?php

namespace Tests\Feature\Analysis;

use App\Analysis\RepositoryAnalyzer;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * The real analysis (git + Python + tree-sitter) of a small project with PHP, TypeScript and Python
 * files that import each other: tests/Fixtures/analyzer-project, committed into the clone folder.
 * Every test is skipped when the analyzer is not installed (analyzer/.venv).
 */
class RepositoryAnalyzerTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessAnalyzerIsInstalled();
    }

    public function test_saves_each_file_with_its_language_lines_size_and_git_blob(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitEverything($this->copyFixtureProject($repository));

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(['files_total' => 16, 'files_analyzed' => 13, 'files_parsed' => 13, 'modules_total' => 9], Arr::except($summary, 'left_out'));
        $this->assertSame(['files' => 0, 'symbols' => 0, 'imports' => 0], $summary['left_out']);
        $this->assertDatabaseHas('repository_files', [
            'repository_id' => $repository->id,
            'path' => 'src/Http/ProductController.php',
            'language' => 'PHP',
            'lines' => 19,
            'size_bytes' => 413,
            'blob_sha' => '0fcf4586ac3cc1837a862fb296740a4cd6d78c85',
        ]);
        $this->assertDatabaseHas('repository_files', ['path' => 'frontend/components/product-card.tsx', 'language' => 'TypeScript', 'lines' => 9]);
        $this->assertDatabaseHas('repository_files', ['path' => 'tools/stats.py', 'language' => 'Python', 'lines' => 10]);
        $this->assertDatabaseHas('repository_files', ['path' => 'README.md', 'language' => 'Markdown', 'lines' => 7]);
    }

    public function test_saves_the_classes_functions_and_methods_of_php_typescript_and_python(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitEverything($this->copyFixtureProject($repository));

        app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame([
            ['class', 'ProductController', 10, 19],
            ['method', 'show', 12, 18],
        ], $this->symbolsOf($repository, 'src/Http/ProductController.php'));
        $this->assertSame([
            ['interface', 'Props', 3, 5],
            ['function', 'ProductCard', 7, 9],
        ], $this->symbolsOf($repository, 'frontend/components/product-card.tsx'));
        $this->assertSame([
            ['function', 'formatPrice', 1, 3],
            ['function', 'roundPrice', 5, 5],
        ], $this->symbolsOf($repository, 'frontend/lib/format.ts'));
        $this->assertSame([
            ['class', 'Stats', 1, 6],
            ['method', '__init__', 2, 3],
            ['method', 'add', 5, 6],
            ['function', 'average', 9, 10],
        ], $this->symbolsOf($repository, 'tools/stats.py'));
    }

    public function test_links_each_import_to_the_file_it_points_to_and_marks_libraries_as_external(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->commitEverything($this->copyFixtureProject($repository));

        app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame([
            ['Illuminate\Http\Request', null, true],
            ['Shop\Models\Category', 'src/Models/Category.php', false],
            ['Shop\Models\Product', 'src/Models/Product.php', false],
            ['Shop\Support\Money', 'src/Support/Money.php', false],
        ], $this->importsOf($repository, 'src/Http/ProductController.php'));
        $this->assertSame([
            ['axios', null, true],
            ['./components/product-card', 'frontend/components/product-card.tsx', false],
            ['@/lib/format', 'frontend/lib/format.ts', false],
        ], $this->importsOf($repository, 'frontend/app.ts'));
        $this->assertSame([
            ['json', null, true],
            ['tools.stats', 'tools/stats.py', false],
        ], $this->importsOf($repository, 'tools/report.py'));
        $this->assertSame([
            ['..report', 'tools/report.py', false],
        ], $this->importsOf($repository, 'tools/cli/main.py'));
    }

    public function test_saves_the_links_the_imports_do_not_show_with_their_file_line_and_kind(): void
    {
        $repository = Repository::factory()->ready()->create();
        $clonePath = $this->copyFixtureProject($repository);
        File::put("{$clonePath}/src/Models/Catalog.php", "<?php\n\nnamespace Shop\\Models;\n\nclass Catalog\n{\n    public function first(): Product\n    {\n        return new Product(100);\n    }\n}\n");
        $this->commitEverything($clonePath);

        app(RepositoryAnalyzer::class)->analyze($repository);

        $catalog = $repository->files()->firstWhere('path', 'src/Models/Catalog.php');
        $product = $repository->files()->firstWhere('path', 'src/Models/Product.php');

        $this->assertDatabaseHas('code_links', ['repository_file_id' => $catalog->id, 'line' => 7, 'kind' => 'reference', 'target_file_id' => $product->id, 'target' => null]);
        $this->assertDatabaseHas('code_links', ['repository_file_id' => $catalog->id, 'line' => 9, 'kind' => 'reference', 'excerpt' => 'return new Product(100);']);
    }

    public function test_groups_the_files_into_modules_and_weighs_the_dependencies_between_them(): void
    {
        $repository = Repository::factory()->ready()->create(['name' => 'magazin']);
        $this->commitEverything($this->copyFixtureProject($repository));

        app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame([
            ['/', 'magazin', 3, 22],
            ['frontend', 'frontend', 1, 9],
            ['frontend/components', 'frontend/components', 1, 9],
            ['frontend/lib', 'frontend/lib', 1, 5],
            ['src/Http', 'src/Http', 1, 19],
            ['src/Models', 'src/Models', 2, 23],
            ['src/Support', 'src/Support', 1, 11],
            ['tools', 'tools', 2, 17],
            ['tools/cli', 'tools/cli', 1, 5],
        ], $repository->modules()->orderBy('path')->get()
            ->map(fn ($module) => [$module->path, $module->name, $module->file_count, $module->line_count])
            ->all());
        $this->assertSame([
            ['frontend', 'frontend/components', 1],
            ['frontend', 'frontend/lib', 1],
            ['frontend/components', 'frontend/lib', 1],
            ['src/Http', 'src/Models', 2],
            ['src/Http', 'src/Support', 1],
            ['src/Models', 'src/Support', 1],
            ['tools/cli', 'tools', 1],
        ], $this->moduleDependenciesOf($repository));
        $this->assertSame('src/Models', RepositoryFile::firstWhere('path', 'src/Models/Category.php')->module->path);
    }

    public function test_skips_libraries_build_output_lock_files_binary_and_big_files_and_symbolic_links(): void
    {
        $repository = Repository::factory()->ready()->create();
        $clonePath = $this->copyFixtureProject($repository);
        File::put("{$clonePath}/logo.png", "\x89PNG\r\n\x1a\n\0\0\0\rIHDR");
        File::put("{$clonePath}/data.txt", str_repeat("o linie\n", 140_000));
        symlink('/etc/hostname', "{$clonePath}/hostname");
        $this->commitEverything($clonePath);

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(19, $summary['files_total']);
        $this->assertSame(13, $summary['files_analyzed']);
        foreach (['vendor/acme/Library.php', 'dist/app.min.js', 'composer.lock', 'logo.png', 'data.txt', 'hostname'] as $skippedPath) {
            $this->assertDatabaseMissing('repository_files', ['path' => $skippedPath]);
        }
    }

    public function test_takes_the_description_from_the_readme_and_records_when_the_analysis_ran(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $repository = Repository::factory()->ready()->create();
        $this->commitEverything($this->copyFixtureProject($repository));

        app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'description' => 'Un magazin mic, folosit ca exemplu în testele analizorului de cod.',
            'analyzed_at' => '2026-11-10 12:00:00',
        ]);
    }

    public function test_a_new_analysis_replaces_the_previous_one_and_leaves_other_repositories_alone(): void
    {
        $otherRepositoryFile = RepositoryFile::factory()->create();
        $repository = Repository::factory()->ready()->create();
        $clonePath = $this->copyFixtureProject($repository);
        $this->commitEverything($clonePath);
        app(RepositoryAnalyzer::class)->analyze($repository);
        $this->git($clonePath, ['rm', '--quiet', 'frontend/app.ts']);
        $this->git($clonePath, ['commit', '--quiet', '-m', 'Fără app.ts']);

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        // Nothing is parsed again: the 12 files left have the same content as at the first analysis.
        $this->assertSame(['files_total' => 15, 'files_analyzed' => 12, 'files_parsed' => 0, 'modules_total' => 8], Arr::except($summary, 'left_out'));
        $this->assertSame(12, $repository->files()->count());
        $this->assertDatabaseMissing('repository_files', ['path' => 'frontend/app.ts']);
        $this->assertDatabaseMissing('modules', ['repository_id' => $repository->id, 'path' => 'frontend']);
        $this->assertSame(5, $repository->moduleDependencies()->count());
        $this->assertModelExists($otherRepositoryFile);
    }

    public function test_a_file_the_analyzer_cannot_read_is_left_out_and_the_other_files_are_saved(): void
    {
        $repository = Repository::factory()->ready()->create();
        $clonePath = $this->copyFixtureProject($repository);
        $this->commitEverything($clonePath);
        File::delete("{$clonePath}/tools/stats.py");

        $summary = app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertSame(12, $summary['files_analyzed']);
        $this->assertDatabaseMissing('repository_files', ['path' => 'tools/stats.py']);
        $this->assertSame([
            ['json', null, true],
            ['tools.stats', null, false],
        ], $this->importsOf($repository, 'tools/report.py'));
    }

    public function test_an_update_parses_only_the_changed_files_and_gives_the_same_analysis_as_from_scratch(): void
    {
        $repository = Repository::factory()->ready()->create();
        $clonePath = $this->copyFixtureProject($repository);
        $this->commitEverything($clonePath);
        app(RepositoryAnalyzer::class)->analyze($repository);
        // One file modified, one added (imported by the modified one), one deleted (imported by an unchanged file).
        File::put("{$clonePath}/src/Http/ProductController.php", str_replace('use Shop\Support\Money;', "use Shop\\Support\\Money;\nuse Shop\\Support\\Vat;", File::get("{$clonePath}/src/Http/ProductController.php")));
        File::put("{$clonePath}/src/Support/Vat.php", "<?php\n\nnamespace Shop\\Support;\n\nfinal class Vat\n{\n    public const RATE = 19;\n}\n");
        $this->git($clonePath, ['rm', '--quiet', 'frontend/lib/format.ts']);
        $this->git($clonePath, ['add', '--all']);
        $this->git($clonePath, ['commit', '--quiet', '-m', 'Schimbări']);

        $update = app(RepositoryAnalyzer::class)->analyze($repository);
        $afterUpdate = $this->analysisSnapshot($repository);
        $fromScratch = app(RepositoryAnalyzer::class)->analyze($repository, fromScratch: true);

        $this->assertSame(2, $update['files_parsed']);
        $this->assertSame(13, $update['files_analyzed']);
        $this->assertSame(13, $fromScratch['files_parsed']);
        $this->assertSame($afterUpdate, $this->analysisSnapshot($repository));
        $this->assertContains(['src/Http/ProductController.php', 'Shop\Support\Vat', 'src/Support/Vat.php'], $afterUpdate['imports']);
        // The import of the deleted file stays, but it no longer points to a file.
        $this->assertContains(['frontend/components/product-card.tsx', '../lib/format', null], $afterUpdate['imports']);
    }

    public function test_unchanged_files_and_modules_keep_their_rows_after_another_analysis(): void
    {
        $repository = Repository::factory()->ready()->create();
        $clonePath = $this->copyFixtureProject($repository);
        $this->commitEverything($clonePath);
        app(RepositoryAnalyzer::class)->analyze($repository);
        $fileIds = $repository->files()->pluck('id', 'path');
        $moduleIds = $repository->modules()->pluck('id', 'path');
        $symbolIds = $repository->files()->firstWhere('path', 'src/Models/Product.php')->symbols()->pluck('id')->all();
        $linesBefore = $repository->files()->firstWhere('path', 'src/Support/Money.php')->lines;
        File::append("{$clonePath}/src/Support/Money.php", "\n// o schimbare\n");
        $this->git($clonePath, ['commit', '--quiet', '--all', '-m', 'Schimbare']);

        app(RepositoryAnalyzer::class)->analyze($repository);

        $this->assertEquals($fileIds, $repository->files()->pluck('id', 'path'));
        $this->assertEquals($moduleIds, $repository->modules()->pluck('id', 'path'));
        $this->assertSame($symbolIds, $repository->files()->firstWhere('path', 'src/Models/Product.php')->symbols()->pluck('id')->all());
        $this->assertSame($linesBefore + 2, $repository->files()->firstWhere('path', 'src/Support/Money.php')->lines);
    }

    /**
     * Everything the analysis saved, by path and never by id, so two analyses can be compared.
     *
     * @return array<string, list<mixed>>
     */
    private function analysisSnapshot(Repository $repository): array
    {
        $files = $repository->files()->with(['module:id,path', 'symbols', 'imports.targetFile:id,path'])->orderBy('path')->get();

        return [
            'modules' => $repository->modules()->orderBy('path')->get()->map->only(['path', 'name', 'file_count', 'line_count'])->all(),
            'files' => $files->map(fn (RepositoryFile $file): array => [$file->path, $file->module->path, $file->language, $file->lines, $file->size_bytes, $file->blob_sha])->all(),
            'symbols' => $files->flatMap(fn (RepositoryFile $file) => $file->symbols->sortBy([['start_line', 'asc'], ['name', 'asc']])
                ->map(fn ($symbol): array => [$file->path, $symbol->kind, $symbol->name, $symbol->start_line, $symbol->end_line]))->values()->all(),
            'imports' => $files->flatMap(fn (RepositoryFile $file) => $file->imports->sortBy('target')
                ->map(fn ($import): array => [$file->path, $import->target, $import->targetFile?->path]))->values()->all(),
            'dependencies' => $repository->moduleDependencies()->with(['fromModule:id,path', 'toModule:id,path'])->get()
                ->map(fn ($dependency): array => [$dependency->fromModule->path, $dependency->toModule->path, $dependency->weight])->sort()->values()->all(),
        ];
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
     * The symbols saved for one file, as [kind, name, start line, end line], in the order of the code.
     *
     * @return list<array{string, string, int, int}>
     */
    private function symbolsOf(Repository $repository, string $path): array
    {
        return $repository->files()->firstWhere('path', $path)->symbols()->orderBy('id')->get()
            ->map(fn ($symbol) => [$symbol->kind, $symbol->name, $symbol->start_line, $symbol->end_line])
            ->all();
    }

    /**
     * The imports saved for one file, as [target, path of the file it points to, is external].
     *
     * @return list<array{string, string|null, bool}>
     */
    private function importsOf(Repository $repository, string $path): array
    {
        return $repository->files()->firstWhere('path', $path)->imports()->with('targetFile')->orderBy('id')->get()
            ->map(fn ($import) => [$import->target, $import->targetFile?->path, $import->is_external])
            ->all();
    }

    /**
     * The dependency graph as [from module, to module, weight], sorted by module paths.
     *
     * @return list<array{string, string, int}>
     */
    private function moduleDependenciesOf(Repository $repository): array
    {
        return $repository->moduleDependencies()->with(['fromModule', 'toModule'])->get()
            ->map(fn ($dependency) => [$dependency->fromModule->path, $dependency->toModule->path, $dependency->weight])
            ->sort()
            ->values()
            ->all();
    }
}
