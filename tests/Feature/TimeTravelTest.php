<?php

namespace Tests\Feature;

use App\Enums\DiagramType;
use App\Incremental\ProcessedCommits;
use App\Models\Commit;
use App\Models\Diagram;
use App\Models\DiagramVersion;
use App\Models\Document;
use App\Models\Module;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The time travel of the „Documentație” and „Diagrame” pages (Faza 9): "?sha=" of a processed commit
 * shows the page's text and the diagrams as they were at that commit.
 *
 * The repository of these tests was processed at three commits: A, B and C (the current one).
 */
class TimeTravelTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const C = 'cccccccccccccccccccccccccccccccccccccccc';

    private Repository $repository;

    /**
     * The successful runs of the three commits, by sha.
     *
     * @var array<string, ProcessingRun>
     */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Repository::factory()->ready()->create(['last_sha' => self::C]);

        $this->processedAt(self::A, ['app/Http', 'app/Old']);
        $this->processedAt(self::B, ['app/Http', 'app/Old']);
        // A failed run and a run that found nothing new do not add stops or change their order.
        ProcessingRun::factory()->for($this->repository)->create(['status' => 'failed', 'to_sha' => str_repeat('d', 40)]);
        $this->processedAt(self::C, ['app/Http', 'app/Jobs']);
        ProcessingRun::factory()->for($this->repository)->create(['status' => 'skipped', 'mode' => 'incremental', 'to_sha' => self::C]);

        Commit::factory()->for($this->repository)->create(['sha' => self::A, 'subject' => 'Primul commit', 'authored_at' => '2026-11-01 10:00:00']);
    }

    public function test_the_processed_commits_are_the_stops_of_the_time_travel(): void
    {
        $commits = app(ProcessedCommits::class);

        $this->assertSame([self::A, self::B, self::C], array_column($commits->of($this->repository), 'sha'));
        $this->assertSame([false, false, true], array_column($commits->of($this->repository), 'is_current'));
        $this->assertSame('Primul commit', $commits->of($this->repository)[0]['subject']);
        $this->assertNull($commits->of($this->repository)[1]['subject']);
        $this->assertTrue($commits->runAt($this->repository, self::B)->is($this->runs[self::B]));
        $this->assertNull($commits->runAt($this->repository, str_repeat('d', 40)));
        $this->assertSame(self::C, $commits->current($this->repository));
    }

    public function test_a_commit_processed_again_later_is_a_stop_at_its_newest_run(): void
    {
        // The branch went back to A (a force push): A is now the current commit, after B.
        $this->processedAt(self::A, ['app/Http']);
        $commits = app(ProcessedCommits::class);

        $this->assertSame([self::B, self::C, self::A], array_column($commits->of($this->repository), 'sha'));
        $this->assertSame(self::A, $commits->current($this->repository));
    }

    public function test_pages_written_by_a_run_that_failed_later_are_shown_at_the_next_commits(): void
    {
        $page = Document::factory()->for($this->repository)->create(['slug' => 'app-http', 'title' => 'app/Http', 'markdown' => '# Acum', 'source_sha' => self::C]);
        // The run of commit d (between B and C) wrote the page, then failed: the text stayed on the site.
        $failedRun = ProcessingRun::where('status', 'failed')->firstOrFail();
        $page->versions()->create(['processing_run_id' => $this->runs[self::A]->id, 'sha' => self::A, 'markdown' => '# La A']);
        $page->versions()->create(['processing_run_id' => $failedRun->id, 'sha' => str_repeat('d', 40), 'markdown' => '# De la rularea eșuată']);
        // A newer commit, so C is an old stop too.
        $this->processedAt(str_repeat('e', 40), ['app/Http']);

        $this->get("/repositories/{$this->repository->id}/docs/app-http?sha=".self::C)
            ->assertInertia(fn (Assert $inertia) => $inertia->where('time_travel.version.markdown', '# De la rularea eșuată'));
        $this->get("/repositories/{$this->repository->id}/docs/app-http?sha=".self::B)
            ->assertInertia(fn (Assert $inertia) => $inertia->where('time_travel.version.markdown', '# La A'));
    }

    public function test_a_page_is_shown_as_it_was_at_the_chosen_commit(): void
    {
        $page = Document::factory()->for($this->repository)->create(['slug' => 'app-http', 'title' => 'app/Http', 'markdown' => '# Acum', 'source_sha' => self::C]);
        $page->versions()->create(['processing_run_id' => $this->runs[self::A]->id, 'sha' => self::A, 'markdown' => '# La A']);
        $page->versions()->create(['processing_run_id' => $this->runs[self::C]->id, 'sha' => self::C, 'markdown' => '# Acum']);

        // The page did not change at B, so at B it is still the version written from A.
        $this->get("/repositories/{$this->repository->id}/docs/app-http?sha=".self::B)
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('document.markdown', '# Acum')
                ->where('time_travel.selected', self::B)
                ->has('time_travel.commits', 3)
                ->where('time_travel.version.sha', self::A)
                ->where('time_travel.version.markdown', '# La A')
                ->where('time_travel.version.same_as_current', false));
    }

    public function test_without_a_commit_or_with_the_newest_one_the_page_is_the_current_one(): void
    {
        Document::factory()->for($this->repository)->overview()->create();

        foreach (['', '?sha='.self::C] as $query) {
            $this->get("/repositories/{$this->repository->id}/docs{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia
                    ->where('time_travel.selected', null)
                    ->where('time_travel.version', null)
                    ->where('time_travel.commits.2.sha', self::C));
        }
    }

    public function test_a_page_written_later_did_not_exist_at_an_older_commit(): void
    {
        $page = Document::factory()->for($this->repository)->create(['slug' => 'app-jobs', 'title' => 'app/Jobs', 'source_sha' => self::C]);
        $page->versions()->create(['processing_run_id' => $this->runs[self::C]->id, 'sha' => self::C, 'markdown' => $page->markdown]);

        $this->get("/repositories/{$this->repository->id}/docs/app-jobs?sha=".self::A)
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('time_travel.selected', self::A)
                ->where('time_travel.version', null));
    }

    public function test_a_commit_that_was_never_processed_is_not_found(): void
    {
        Document::factory()->for($this->repository)->overview()->create();

        $this->get("/repositories/{$this->repository->id}/docs?sha=".str_repeat('d', 40))->assertNotFound();
        $this->get("/repositories/{$this->repository->id}/docs?sha=nu-e-un-sha")->assertNotFound();
        $this->get("/repositories/{$this->repository->id}/diagrams?sha=".str_repeat('e', 40))->assertNotFound();
    }

    public function test_the_diagrams_are_shown_as_they_were_and_compared_with_the_current_ones(): void
    {
        Module::factory()->for($this->repository)->create(['path' => 'app/Http']);
        Module::factory()->for($this->repository)->create(['path' => 'app/Jobs']);
        Diagram::factory()->for($this->repository)->create(['mermaid' => 'flowchart TB; acum', 'source_sha' => self::C]);
        Diagram::factory()->for($this->repository)->create(['type' => DiagramType::Context, 'mermaid' => 'flowchart TB; context', 'meta' => ['title' => 'Context'], 'source_sha' => self::C]);
        DiagramVersion::factory()->for($this->repository)->create(['processing_run_id' => $this->runs[self::A]->id, 'sha' => self::A, 'mermaid' => 'flowchart TB; la-a']);
        DiagramVersion::factory()->for($this->repository)->create(['processing_run_id' => $this->runs[self::A]->id, 'sha' => self::A, 'type' => DiagramType::Context, 'mermaid' => 'flowchart TB; context', 'meta' => ['title' => 'Context']]);
        DiagramVersion::factory()->for($this->repository)->create(['processing_run_id' => $this->runs[self::A]->id, 'sha' => self::A, 'type' => DiagramType::Flow, 'key' => 'fluxul-vechi', 'mermaid' => 'flowchart TD; vechi', 'meta' => ['title' => 'Fluxul vechi', 'sources' => [['path' => 'app.php', 'start_line' => 1, 'end_line' => 2]]]]);
        DiagramVersion::factory()->for($this->repository)->create(['processing_run_id' => $this->runs[self::B]->id, 'sha' => self::B, 'type' => DiagramType::Flow, 'key' => 'fluxul-vechi', 'mermaid' => null, 'meta' => null]);
        DiagramVersion::factory()->for($this->repository)->create(['processing_run_id' => $this->runs[self::C]->id, 'sha' => self::C, 'mermaid' => 'flowchart TB; acum']);

        $this->get("/repositories/{$this->repository->id}/diagrams?sha=".self::A)
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('diagrams.layers.mermaid', 'flowchart TB; la-a')
                ->where('diagrams.layers.source_sha', self::A)
                ->where('diagrams.context.mermaid', 'flowchart TB; context')
                ->has('diagrams.flows', 1)
                ->where('diagrams.flows.0.sources.0.url', "https://github.com/{$this->repository->owner}/{$this->repository->name}/blob/".self::A.'/app.php#L1-L2')
                ->where('time_travel.selected', self::A)
                // The layers view was drawn again since and the old flow is gone; the context view is the same.
                ->where('time_travel.changes.diagrams', ['Arhitectura pe straturi', 'Fluxul vechi'])
                ->where('time_travel.changes.modules_added', ['app/Jobs'])
                ->where('time_travel.changes.modules_removed', ['app/Old'])
                ->where('time_travel.changes.modules_added_count', 1));

        // At B the flow was already gone.
        $this->get("/repositories/{$this->repository->id}/diagrams?sha=".self::B)
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('diagrams.flows', [])
                ->where('diagrams.layers.mermaid', 'flowchart TB; la-a'));

        $this->get("/repositories/{$this->repository->id}/diagrams")
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('diagrams.layers.mermaid', 'flowchart TB; acum')
                ->where('time_travel.selected', null)
                ->where('time_travel.changes', null));
    }

    public function test_the_module_comparison_is_unknown_for_a_commit_processed_before_the_list_was_saved(): void
    {
        $this->runs[self::A]->update(['module_paths' => null]);

        $this->get("/repositories/{$this->repository->id}/diagrams?sha=".self::A)
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('time_travel.changes.modules_added', null)
                ->where('time_travel.changes.modules_removed', null)
                ->where('time_travel.changes.modules_added_count', null));
    }

    /**
     * A successful run of the repository at a commit, with the modules found there.
     *
     * @param  list<string>  $modulePaths
     */
    private function processedAt(string $sha, array $modulePaths): void
    {
        $this->runs[$sha] = ProcessingRun::factory()->for($this->repository)->create([
            'status' => 'succeeded',
            'to_sha' => $sha,
            'module_paths' => $modulePaths,
            'finished_at' => now(),
        ]);
    }
}
