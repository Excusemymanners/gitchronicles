<?php

namespace Tests\Feature\Incremental;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Incremental\RepositoryCompleteness;
use App\Models\Commit;
use App\Models\Document;
use App\Models\MediaAsset;
use App\Models\Module;
use App\Models\ProcessingRun;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which artifacts a repository has and which are missing (RepositoryCompleteness), from its rows only.
 */
class RepositoryCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_complete_repository_has_every_artifact_of_the_pipeline_for_its_current_commit(): void
    {
        $repository = Repository::factory()->complete()->create();
        $this->succeededRun($repository, $repository->last_sha);

        $completeness = RepositoryCompleteness::of($repository);

        $this->assertFalse($completeness->isIncomplete());
        $this->assertSame([], $completeness->missing());
        $this->assertSame('', $completeness->missingText());

        foreach (array_keys(RepositoryCompleteness::PIPELINE_ARTIFACTS) as $artifact) {
            $this->assertSame(RepositoryCompleteness::READY, $completeness->state($artifact), $artifact);
            $this->assertTrue($completeness->isCurrent($artifact), $artifact);
        }
    }

    /**
     * @return array<string, array{string, callable(Repository): void}>
     */
    public static function missingArtifacts(): array
    {
        return [
            'structure' => [RepositoryCompleteness::STRUCTURE, fn (Repository $repository) => $repository->update(['analyzed_at' => null])],
            'history' => [RepositoryCompleteness::HISTORY, fn (Repository $repository) => $repository->commits()->delete()],
            'documentation' => [RepositoryCompleteness::DOCUMENTATION, fn (Repository $repository) => Document::whereBelongsTo($repository)->delete()],
            'diagrams' => [RepositoryCompleteness::DIAGRAMS, fn (Repository $repository) => $repository->diagrams()->delete()],
            'assistant' => [RepositoryCompleteness::ASSISTANT, fn (Repository $repository) => $repository->codeChunks()->delete()],
        ];
    }

    /**
     * @param  callable(Repository): void  $remove
     */
    #[DataProvider('missingArtifacts')]
    public function test_each_missing_artifact_makes_the_repository_incomplete(string $artifact, callable $remove): void
    {
        $repository = Repository::factory()->complete()->create();
        $remove($repository);

        $completeness = RepositoryCompleteness::of($repository->fresh());

        $this->assertTrue($completeness->isIncomplete());
        $this->assertSame([$artifact], $completeness->missing());
        $this->assertSame(RepositoryCompleteness::MISSING, $completeness->state($artifact));
        $this->assertFalse($completeness->isCurrent($artifact));
    }

    public function test_a_repository_processed_by_an_older_pipeline_misses_everything_but_the_structure(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $module = Module::factory()->for($repository)->create(['path' => 'src', 'name' => 'src']);
        RepositoryFile::factory()->for($repository)->create(['module_id' => $module->id, 'path' => 'src/index.ts', 'language' => 'TypeScript']);

        $completeness = RepositoryCompleteness::of($repository);

        $this->assertSame(['history', 'documentation', 'diagrams', 'assistant'], $completeness->missing());
        $this->assertSame('history, documentation, diagrams and assistant index', $completeness->missingText());
        $this->assertSame(RepositoryCompleteness::READY, $completeness->state(RepositoryCompleteness::STRUCTURE));
    }

    public function test_a_repository_without_source_files_needs_no_diagrams_and_no_assistant_index(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $root = Module::factory()->for($repository)->create(['path' => '/', 'name' => '/']);
        RepositoryFile::factory()->for($repository)->create(['module_id' => $root->id, 'path' => 'README', 'language' => null]);
        Commit::factory()->for($repository)->create(['sha' => $repository->last_sha]);
        Document::factory()->for($repository)->create(['slug' => Document::OVERVIEW]);

        $completeness = RepositoryCompleteness::of($repository);

        $this->assertFalse($completeness->isIncomplete());
        $this->assertSame(RepositoryCompleteness::NOT_APPLICABLE, $completeness->state(RepositoryCompleteness::DIAGRAMS));
        $this->assertSame(RepositoryCompleteness::NOT_APPLICABLE, $completeness->state(RepositoryCompleteness::ASSISTANT));
    }

    public function test_code_only_in_tests_needs_an_assistant_index_but_no_diagrams(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $tests = Module::factory()->for($repository)->create(['path' => 'tests', 'name' => 'tests']);
        RepositoryFile::factory()->for($repository)->create(['module_id' => $tests->id, 'path' => 'tests/ExampleTest.php', 'language' => 'PHP']);

        $completeness = RepositoryCompleteness::of($repository);

        $this->assertSame(RepositoryCompleteness::NOT_APPLICABLE, $completeness->state(RepositoryCompleteness::DIAGRAMS));
        $this->assertSame(RepositoryCompleteness::MISSING, $completeness->state(RepositoryCompleteness::ASSISTANT));
    }

    public function test_modules_are_expected_only_when_the_analysis_found_files(): void
    {
        $withoutFiles = Repository::factory()->ready()->analyzed()->create();
        $withFilesWithoutModules = Repository::factory()->ready()->analyzed()->create();
        RepositoryFile::factory()->for($withFilesWithoutModules)->create(['path' => 'README.md', 'language' => 'Markdown']);

        $this->assertSame(RepositoryCompleteness::READY, RepositoryCompleteness::of($withoutFiles)->state(RepositoryCompleteness::STRUCTURE));
        $this->assertSame(RepositoryCompleteness::MISSING, RepositoryCompleteness::of($withFilesWithoutModules)->state(RepositoryCompleteness::STRUCTURE));
    }

    public function test_the_pipeline_artifacts_are_current_only_when_the_newest_good_run_reached_the_current_commit(): void
    {
        $repository = Repository::factory()->complete()->create();
        $this->succeededRun($repository, str_repeat('a', 40));
        ProcessingRun::factory()->for($repository)->create(['status' => ProcessingRunStatus::Failed, 'to_sha' => $repository->last_sha]);

        $this->assertFalse(RepositoryCompleteness::of($repository)->isCurrent(RepositoryCompleteness::DOCUMENTATION));

        ProcessingRun::factory()->for($repository)->create(['mode' => ProcessingMode::Incremental, 'status' => ProcessingRunStatus::Skipped, 'to_sha' => $repository->last_sha]);

        $this->assertTrue(RepositoryCompleteness::of($repository)->isCurrent(RepositoryCompleteness::DOCUMENTATION));
    }

    public function test_the_video_and_the_slides_are_reported_but_never_make_a_repository_incomplete(): void
    {
        $repository = Repository::factory()->complete()->create();

        $completeness = RepositoryCompleteness::of($repository);
        $this->assertSame(RepositoryCompleteness::MISSING, $completeness->state(RepositoryCompleteness::VIDEO));
        $this->assertFalse($completeness->isIncomplete());

        MediaAsset::factory()->for($repository)->create(['kind' => MediaKind::Video, 'status' => MediaStatus::Ready, 'source_sha' => $repository->last_sha]);
        MediaAsset::factory()->for($repository)->create(['kind' => MediaKind::Slides, 'status' => MediaStatus::Failed, 'error' => 'Randarea a eșuat.']);

        $completeness = RepositoryCompleteness::of($repository);
        $this->assertSame(RepositoryCompleteness::READY, $completeness->state(RepositoryCompleteness::VIDEO));
        $this->assertTrue($completeness->isCurrent(RepositoryCompleteness::VIDEO));
        $this->assertSame(RepositoryCompleteness::FAILED, $completeness->state(RepositoryCompleteness::SLIDES));
        $this->assertFalse($completeness->isIncomplete());

        $repository->mediaAssets()->where('kind', MediaKind::Video)->update(['source_sha' => str_repeat('b', 40)]);
        $repository->mediaAssets()->where('kind', MediaKind::Slides)->update(['status' => MediaStatus::Running, 'error' => null]);

        $completeness = RepositoryCompleteness::of($repository);
        $this->assertSame(RepositoryCompleteness::READY, $completeness->state(RepositoryCompleteness::VIDEO));
        $this->assertFalse($completeness->isCurrent(RepositoryCompleteness::VIDEO));
        $this->assertSame(RepositoryCompleteness::WORKING, $completeness->state(RepositoryCompleteness::SLIDES));
    }

    public function test_the_scheduled_check_heals_a_repository_only_once_per_commit(): void
    {
        $repository = Repository::factory()->ready()->analyzed()->create();
        $this->succeededRun($repository, $repository->last_sha);

        $this->assertTrue(RepositoryCompleteness::of($repository)->healsAutomatically());

        ProcessingRun::factory()->for($repository)->create(['mode' => ProcessingMode::Full, 'status' => ProcessingRunStatus::Succeeded, 'to_sha' => str_repeat('c', 40)]);

        $this->assertTrue(RepositoryCompleteness::of($repository)->healsAutomatically(), 'A full run at another commit does not count.');

        ProcessingRun::factory()->for($repository)->create(['mode' => ProcessingMode::Full, 'status' => ProcessingRunStatus::Succeeded, 'to_sha' => $repository->last_sha]);

        $this->assertFalse(RepositoryCompleteness::of($repository)->healsAutomatically());
        $this->assertFalse(RepositoryCompleteness::of(Repository::factory()->complete()->create())->healsAutomatically());
    }

    /**
     * A run of the pipeline that ended well at the given commit.
     */
    private function succeededRun(Repository $repository, string $sha): ProcessingRun
    {
        return ProcessingRun::factory()->for($repository)->create([
            'status' => ProcessingRunStatus::Succeeded,
            'to_sha' => $sha,
            'finished_at' => now(),
        ]);
    }
}
