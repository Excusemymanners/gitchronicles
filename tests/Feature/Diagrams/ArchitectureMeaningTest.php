<?php

namespace Tests\Feature\Diagrams;

use App\Diagrams\ArchitectureDiagramBuilder;
use App\Models\FileImport;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSmallProject;
use Tests\TestCase;

/**
 * The findings and the evidence stored with the architecture diagrams, on the small project of
 * BuildsSmallProject (modules and imports made by hand, so every number can be checked by eye).
 */
class ArchitectureMeaningTest extends TestCase
{
    use BuildsSmallProject;
    use RefreshDatabase;

    /**
     * @return array<string, array{meta: array<string, mixed>}>
     */
    private function build(): array
    {
        $repository = Repository::factory()->ready()->create();
        $this->smallProject($repository);

        return collect(app(ArchitectureDiagramBuilder::class)->build($repository))->mapWithKeys(fn (array $diagram): array => [$diagram['type']->value => $diagram])->all();
    }

    public function test_the_layers_view_stores_findings_computed_from_the_imports(): void
    {
        $findings = $this->build()['layers']['meta']['findings'];

        $this->assertSame(['module' => 'app/Models', 'modules' => 2, 'imports' => 4], $findings['core'][0]);
        $this->assertSame(['module' => 'app/Http', 'modules' => 2, 'imports' => 4], $findings['coordinators'][0]);
        $this->assertSame([['modules' => ['app/Models', 'app/Support'], 'size' => 2]], $findings['cycles']);
        $this->assertSame(['count' => 1, 'imports' => 1, 'pairs' => [['from' => 'app/Models', 'to' => 'app/Support', 'imports' => 1]]], $findings['upward']);
        $this->assertSame(['resources/js'], $findings['isolated']);
        $this->assertSame([['module' => 'app/Http', 'commits' => 2], ['module' => 'app/Models', 'commits' => 1]], $findings['busiest']);
    }

    public function test_the_coverage_counts_the_modules_lines_and_imports_that_are_not_drawn(): void
    {
        $coverage = $this->build()['layers']['meta']['findings']['coverage'];

        // Eight imports in all; the arrows between layers stand for all of them, none is inside one layer.
        $this->assertSame(['shown_modules' => 5, 'total_modules' => 5, 'shown_lines' => 1030, 'total_lines' => 1030, 'total_imports' => 8, 'undrawn_imports' => 0], $coverage);
    }

    public function test_a_finding_that_does_not_apply_is_absent(): void
    {
        $repository = Repository::factory()->ready()->create();
        $this->smallProject($repository);
        $repository->commits()->delete();

        $meta = collect(app(ArchitectureDiagramBuilder::class)->build($repository))->firstWhere('type.value', 'layers')['meta'];

        $this->assertArrayNotHasKey('busiest', $meta['findings']);
    }

    public function test_every_layer_arrow_has_its_import_count_and_up_to_three_examples(): void
    {
        $evidence = collect($this->build()['layers']['meta']['evidence'])->keyBy(fn (array $row): string => "{$row['from']}>{$row['to']}");

        $this->assertSame(2, $evidence['layer_entry>layer_web']['imports']);
        $this->assertSame([
            ['from' => 'routes/api.php', 'to' => 'app/Http/B.php'],
            ['from' => 'routes/web.php', 'to' => 'app/Http/A.php'],
        ], $evidence['layer_entry>layer_web']['examples']);
        $this->assertCount(3, $evidence['layer_web>layer_data']['examples']);
        $this->assertSame(3, $evidence['layer_web>layer_data']['imports']);
        $this->assertSame('Web/API', $evidence['layer_web>layer_data']['from_label']);
    }

    public function test_every_component_arrow_has_evidence_and_the_classes_of_each_node(): void
    {
        $meta = $this->build()['component']['meta'];
        $arrows = collect($meta['evidence']);

        $this->assertCount(5, $arrows);

        $httpToModels = $arrows->firstWhere(fn (array $row): bool => $row['from_label'] === 'app/Http' && $row['to_label'] === 'app/Models');

        $this->assertSame(3, $httpToModels['imports']);
        $this->assertSame([$httpToModels['from'], $httpToModels['to']], $httpToModels['nodes']);
        $this->assertSame(['app/Http/A.php', 'app/Http/B.php', 'app/Http/C.php'], array_column($httpToModels['examples'], 'from'));
        $this->assertArrayHasKey('findings', $meta);
        $this->assertArrayNotHasKey('upward', $meta['findings']);
    }

    public function test_an_example_in_a_test_file_comes_after_the_code_that_uses_the_module(): void
    {
        $repository = Repository::factory()->ready()->create();
        $modules = $this->smallProject($repository);
        $testFile = RepositoryFile::factory()->for($repository)->for($modules['app/Http'])->create(['path' => 'app/Http/A.test.php', 'language' => 'PHP']);
        $target = RepositoryFile::where('path', 'app/Models/M.php')->first();
        FileImport::factory()->create(['repository_file_id' => $testFile->id, 'repository_id' => $repository->id, 'target' => 'x', 'target_file_id' => $target->id, 'is_external' => false]);

        $meta = collect(app(ArchitectureDiagramBuilder::class)->build($repository))->firstWhere('type.value', 'component')['meta'];
        $row = collect($meta['evidence'])->first(fn (array $row): bool => $row['from_label'] === 'app/Http' && $row['to_label'] === 'app/Models');

        $this->assertSame(['app/Http/A.php', 'app/Http/B.php', 'app/Http/C.php'], array_column($row['examples'], 'from'));
    }
}
