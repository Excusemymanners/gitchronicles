<?php

namespace Tests\Feature\Diagrams;

use App\Diagrams\DiagramHistory;
use App\Enums\DiagramType;
use App\Models\Diagram;
use App\Models\DiagramVersion;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagramHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SHA_C = 'cccccccccccccccccccccccccccccccccccccccc';

    public function test_the_first_diagrams_become_their_first_versions(): void
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => self::SHA_A]);
        Diagram::factory()->for($repository)->create(['mermaid' => 'flowchart TB; a-->b']);
        Diagram::factory()->for($repository)->flow()->create();

        $added = $this->record($repository);

        $this->assertSame(2, $added);
        $this->assertDatabaseHas('diagram_versions', [
            'repository_id' => $repository->id,
            'type' => 'layers',
            'key' => '',
            'sha' => self::SHA_A,
            'mermaid' => 'flowchart TB; a-->b',
        ]);
        $this->assertSame('Adăugarea unui repository', DiagramVersion::firstWhere('type', 'flow')->meta['title']);
    }

    public function test_a_version_is_added_only_when_the_code_of_the_diagram_changed(): void
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => self::SHA_A]);
        $layers = Diagram::factory()->for($repository)->create(['mermaid' => 'flowchart TB; a-->b']);
        $context = Diagram::factory()->for($repository)->create(['type' => DiagramType::Context, 'mermaid' => 'flowchart TB; user-->system']);
        $this->record($repository);
        $repository->update(['last_sha' => self::SHA_B]);
        $layers->update(['mermaid' => 'flowchart TB; a-->b; b-->c', 'source_sha' => self::SHA_B]);
        $context->update(['source_sha' => self::SHA_B]);

        $added = $this->record($repository);

        $this->assertSame(1, $added);
        $this->assertSame([self::SHA_A, self::SHA_B], DiagramVersion::where('type', 'layers')->orderBy('id')->pluck('sha')->all());
        $this->assertSame([self::SHA_A], DiagramVersion::where('type', 'context')->pluck('sha')->all());
    }

    public function test_a_diagram_that_disappeared_gets_one_version_without_code(): void
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => self::SHA_A]);
        $flow = Diagram::factory()->for($repository)->flow('fluxul-vechi')->create();
        $this->record($repository);
        $repository->update(['last_sha' => self::SHA_B]);
        $flow->delete();
        Diagram::factory()->for($repository)->flow('fluxul-nou')->create();

        $this->record($repository);
        $addedAgain = $this->record($repository);

        $this->assertDatabaseHas('diagram_versions', ['type' => 'flow', 'key' => 'fluxul-vechi', 'sha' => self::SHA_B, 'mermaid' => null]);
        $this->assertDatabaseHas('diagram_versions', ['type' => 'flow', 'key' => 'fluxul-nou', 'sha' => self::SHA_B]);
        $this->assertSame(0, $addedAgain);
        $this->assertSame(3, DiagramVersion::count());
    }

    public function test_at_gives_the_diagrams_as_they_were_right_after_a_run(): void
    {
        $repository = Repository::factory()->ready()->create(['last_sha' => self::SHA_A]);
        $layers = Diagram::factory()->for($repository)->create(['mermaid' => 'flowchart TB; a-->b']);
        $flow = Diagram::factory()->for($repository)->flow('fluxul-vechi')->create();
        $runA = $this->runAt($repository);
        app(DiagramHistory::class)->record($repository, $runA);
        $repository->update(['last_sha' => self::SHA_B]);
        $flow->delete();
        $runB = $this->runAt($repository);
        app(DiagramHistory::class)->record($repository, $runB);
        $repository->update(['last_sha' => self::SHA_C]);
        $layers->update(['mermaid' => 'flowchart TB; a-->c']);
        $runC = $this->runAt($repository);
        app(DiagramHistory::class)->record($repository, $runC);

        $atA = app(DiagramHistory::class)->at($repository, $runA);
        $atB = app(DiagramHistory::class)->at($repository, $runB);
        $atC = app(DiagramHistory::class)->at($repository, $runC);

        $this->assertSame(['flow', 'layers'], $atA->map(fn (DiagramVersion $version): string => $version->type->value)->sort()->values()->all());
        $this->assertSame(['flowchart TB; a-->b'], $atB->pluck('mermaid')->all());
        $this->assertSame(['flowchart TB; a-->c'], $atC->pluck('mermaid')->all());
        $this->assertSame([$runA->id, $runA->id, $runB->id, $runC->id], DiagramVersion::orderBy('id')->pluck('processing_run_id')->all());
    }

    /**
     * Record the diagrams' changes as a run of the repository at its current commit would.
     */
    private function record(Repository $repository): int
    {
        return app(DiagramHistory::class)->record($repository, $this->runAt($repository));
    }

    /**
     * A run of the repository at its current commit.
     */
    private function runAt(Repository $repository): ProcessingRun
    {
        return ProcessingRun::factory()->for($repository)->create(['status' => 'succeeded', 'to_sha' => $repository->last_sha]);
    }
}
