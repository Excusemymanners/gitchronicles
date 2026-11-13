<?php

namespace Tests\Feature\Diagrams;

use App\Diagrams\ArchitectureDiagramBuilder;
use App\Models\CodeSymbol;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\FileImport;
use App\Models\Module;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSmallProject;
use Tests\TestCase;

/**
 * A repository with code in only two folders (and its root) gets its components diagram at file level,
 * with the same findings and evidence as the module diagrams, over files. Bigger repositories keep
 * the diagram of modules.
 */
class FileLevelComponentsTest extends TestCase
{
    use BuildsSmallProject;
    use RefreshDatabase;

    /**
     * src/main.py → src/store.py (2 imports), src/main.py → lib/format.py, src/store.py → lib/format.py,
     * lib/format.py → src/store.py (a loop), lib/orphan.py imports nothing; tests and the README stay out.
     *
     * @return array<string, mixed> the components diagram
     */
    private function componentsOfATwoFolderRepository(): array
    {
        $repository = Repository::factory()->ready()->create(['name' => 'flat-tool']);
        $src = Module::factory()->for($repository)->create(['path' => 'src', 'name' => 'src', 'file_count' => 2, 'line_count' => 150]);
        $lib = Module::factory()->for($repository)->create(['path' => 'lib', 'name' => 'lib', 'file_count' => 2, 'line_count' => 60]);
        $tests = Module::factory()->for($repository)->create(['path' => 'tests', 'name' => 'tests', 'file_count' => 1, 'line_count' => 20]);
        $root = Module::factory()->for($repository)->create(['path' => '/', 'name' => 'flat-tool', 'file_count' => 1, 'line_count' => 5]);

        $files = [];

        foreach ([['src/main.py', $src, 100], ['src/store.py', $src, 50], ['lib/format.py', $lib, 40], ['lib/orphan.py', $lib, 20], ['tests/test_main.py', $tests, 20], ['run', $root, 5]] as [$path, $module, $lines]) {
            $files[$path] = RepositoryFile::factory()->for($repository)->for($module)->create(['path' => $path, 'language' => 'Python', 'lines' => $lines]);
        }

        foreach ([['src/main.py', 'src/store.py'], ['src/main.py', 'src/store.py'], ['src/main.py', 'lib/format.py'], ['src/store.py', 'lib/format.py'], ['lib/format.py', 'src/store.py'], ['tests/test_main.py', 'src/main.py']] as [$from, $to]) {
            FileImport::factory()->create(['repository_id' => $repository->id, 'repository_file_id' => $files[$from]->id, 'target' => $to, 'target_file_id' => $files[$to]->id, 'is_external' => false]);
        }

        CodeSymbol::factory()->create(['repository_id' => $repository->id, 'repository_file_id' => $files['src/main.py']->id, 'kind' => 'function', 'name' => 'run_app', 'start_line' => 1, 'end_line' => 40]);
        CodeSymbol::factory()->create(['repository_id' => $repository->id, 'repository_file_id' => $files['src/store.py']->id, 'kind' => 'class', 'name' => 'Store', 'start_line' => 1, 'end_line' => 30]);

        $commit = Commit::factory()->create(['repository_id' => $repository->id]);
        CommitFile::factory()->create(['commit_id' => $commit->id, 'path' => 'src/store.py']);

        return collect(app(ArchitectureDiagramBuilder::class)->build($repository))->firstWhere('type.value', 'component');
    }

    public function test_a_repository_with_code_in_two_folders_gets_a_components_diagram_of_files(): void
    {
        $diagram = $this->componentsOfATwoFolderRepository();

        $this->assertSame('files', $diagram['meta']['level']);
        $this->assertSame(['f1' => 'lib/format.py', 'f2' => 'src/main.py', 'f3' => 'src/store.py'], $diagram['meta']['nodes']);
        $this->assertSame(['lib/orphan.py', 'run'], $diagram['meta']['unconnected']);
        $this->assertStringContainsString('f2["<b>main.py</b><br>src"]', $diagram['mermaid']);
        $this->assertStringContainsString('f2 -->|"uses · 2"| f3', $diagram['mermaid']);
        $this->assertStringNotContainsString('orphan', $diagram['mermaid']);
        $this->assertStringNotContainsString('test_main', $diagram['mermaid']);
    }

    public function test_the_findings_are_computed_over_files(): void
    {
        $findings = $this->componentsOfATwoFolderRepository()['meta']['findings'];

        $this->assertSame('files', $findings['unit']);
        $this->assertSame(['module' => 'src/store.py', 'modules' => 2, 'imports' => 3], $findings['core'][0]);
        $this->assertSame(['module' => 'src/main.py', 'modules' => 2, 'imports' => 3], $findings['coordinators'][0]);
        $this->assertSame([['modules' => ['lib/format.py', 'src/store.py'], 'size' => 2]], $findings['cycles']);
        $this->assertSame([['module' => 'src/store.py', 'commits' => 1]], $findings['busiest']);
        $this->assertSame(['shown_modules' => 3, 'total_modules' => 5, 'shown_lines' => 190, 'total_lines' => 215, 'total_imports' => 5, 'undrawn_imports' => 0], $findings['coverage']);
    }

    public function test_every_arrow_has_its_import_count_and_the_pair_of_files_as_evidence(): void
    {
        $evidence = collect($this->componentsOfATwoFolderRepository()['meta']['evidence'])->keyBy(fn (array $row): string => $row['from_label'].'>'.$row['to_label']);

        $this->assertCount(4, $evidence);
        $this->assertSame(2, $evidence['src/main.py>src/store.py']['imports']);
        $this->assertSame([['from' => 'src/main.py', 'to' => 'src/store.py']], $evidence['src/main.py>src/store.py']['examples']);
        $this->assertSame(['f2', 'f3'], $evidence['src/main.py>src/store.py']['nodes']);
    }

    public function test_a_repository_with_three_modules_keeps_the_components_diagram_of_modules(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->smallProject($repository);

        $diagram = collect(app(ArchitectureDiagramBuilder::class)->build($repository))->firstWhere('type.value', 'component');

        $this->assertArrayNotHasKey('level', $diagram['meta']);
        $this->assertArrayNotHasKey('unit', $diagram['meta']['findings']);
    }
}
