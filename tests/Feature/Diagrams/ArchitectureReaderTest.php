<?php

namespace Tests\Feature\Diagrams;

use App\Ai\AiException;
use App\Ai\TextGenerator;
use App\Diagrams\ArchitectureRefresher;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSmallProject;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The interpretation of the architecture diagrams: one call for all of them, checked before it is
 * saved, cached by input hash, and replaced by a reading composed from the findings when AI is off,
 * fails or is rejected. Claude is never called: FakeTextGenerator answers.
 */
class ArchitectureReaderTest extends TestCase
{
    use BuildsSmallProject;
    use RefreshDatabase;

    private function repository(): Repository
    {
        $repository = Repository::factory()->ready()->create(['description' => 'A shop that sells things.']);
        $this->smallProject($repository);

        return $repository;
    }

    private function answer(string $layersReading = 'Requests enter through `routes`, pass `app/Http` and end in `app/Models`, 3 imports deep.'): string
    {
        return json_encode(['diagrams' => [
            'layers' => ['reading' => $layersReading, 'worth' => '`app/Models` imports `app/Support` against the layer order.'],
            'component' => ['reading' => '`app/Http` coordinates the work of the application.', 'worth' => 'The core is `app/Models`.'],
        ]]);
    }

    public function test_one_call_writes_the_reading_of_every_diagram_and_marks_it_as_written_by_ai(): void
    {
        $repository = $this->repository();
        $this->app->instance(TextGenerator::class, $fake = new FakeTextGenerator([$this->answer()]));

        $outcome = app(ArchitectureRefresher::class)->refresh($repository);

        $fake->assertSentCount(1);
        $this->assertSame('ai', $outcome['origin']);
        $this->assertStringContainsString('A shop that sells things.', $fake->requests[0]['user']);
        $this->assertStringContainsString('"upward"', $fake->requests[0]['user']);

        $layers = $repository->diagrams()->firstWhere('type', 'layers')->meta['interpretation'];
        $this->assertSame('ai', $layers['source']);
        $this->assertStringContainsString('`app/Http`', $layers['reading']);
    }

    public function test_the_same_input_does_not_ask_claude_again(): void
    {
        $repository = $this->repository();
        $this->app->instance(TextGenerator::class, $fake = new FakeTextGenerator([$this->answer()]));
        app(ArchitectureRefresher::class)->refresh($repository);

        $outcome = app(ArchitectureRefresher::class)->refresh($repository);

        $fake->assertSentCount(1);
        $this->assertSame('cache', $outcome['origin']);
        $this->assertNull($outcome['result']);
        $this->assertSame('ai', $repository->diagrams()->firstWhere('type', 'layers')->meta['interpretation']['source']);
    }

    public function test_a_reading_with_an_unknown_module_is_rejected_and_that_diagram_gets_the_composed_reading(): void
    {
        $repository = $this->repository();
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([$this->answer('Everything goes through `app/Billing`.')]));

        $outcome = app(ArchitectureRefresher::class)->refresh($repository);

        $this->assertSame('ai', $outcome['origin']);
        $this->assertSame(['source' => 'composed', 'reason' => 'rejected'], collect($repository->diagrams()->firstWhere('type', 'layers')->meta['interpretation'])->only(['source', 'reason'])->all());
        $this->assertSame('ai', $repository->diagrams()->firstWhere('type', 'component')->meta['interpretation']['source']);
    }

    public function test_without_ai_the_diagrams_with_findings_are_composed_and_the_others_have_no_reading(): void
    {
        $repository = $this->repository();

        $outcome = app(ArchitectureRefresher::class)->refresh($repository);

        $this->assertSame('fallback', $outcome['origin']);
        $interpretation = $repository->diagrams()->firstWhere('type', 'layers')->meta['interpretation'];
        $this->assertSame('composed', $interpretation['source']);
        $this->assertSame('disabled', $interpretation['reason']);
        $this->assertArrayNotHasKey('reading', $interpretation);
        $this->assertNull($repository->diagrams()->firstWhere('type', 'component')->meta['interpretation']['reading'] ?? null);
    }

    public function test_when_claude_fails_the_composed_reading_is_saved_and_the_next_build_asks_again(): void
    {
        $repository = $this->repository();
        $this->app->instance(TextGenerator::class, $failing = new FakeTextGenerator(exception: new AiException('Claude is busy.')));
        app(ArchitectureRefresher::class)->refresh($repository);

        $this->assertSame('failed', $repository->diagrams()->firstWhere('type', 'layers')->meta['interpretation']['reason']);

        $this->app->instance(TextGenerator::class, $working = new FakeTextGenerator([$this->answer()]));
        app(ArchitectureRefresher::class)->refresh($repository);

        $failing->assertSentCount(1);
        $working->assertSentCount(1);
        $this->assertSame('ai', $repository->diagrams()->firstWhere('type', 'layers')->meta['interpretation']['source']);
    }
}
