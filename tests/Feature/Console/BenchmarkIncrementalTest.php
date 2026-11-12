<?php

namespace Tests\Feature\Console;

use App\Models\Document;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ProcessesFixtureProject;
use Tests\TestCase;

/**
 * gitchronicle:benchmark on the fixture project: its last two commits are replayed in a scratch
 * environment, incrementally and from zero, without AI and without touching the application's data.
 */
class BenchmarkIncrementalTest extends TestCase
{
    use ProcessesFixtureProject;
    use RefreshDatabase;

    public function test_replays_the_newest_commits_and_saves_the_measurements(): void
    {
        $this->skipUnlessAnalyzerIsInstalled();
        $repository = $this->repositoryWithFixtureOrigin();
        $firstChange = $this->pushToOrigin(['src/Support/Money.php' => "<?php\n\nnamespace Shop\\Support;\n\nfinal class Money\n{\n    public static function zero(): string\n    {\n        return '0 lei';\n    }\n}\n"]);
        $secondChange = $this->pushToOrigin(['tools/extra.py' => "def extra():\n    return 1\n"], delete: ['frontend/app.ts']);
        $this->process($repository);
        $documentsBefore = Document::count();
        $this->travelTo('2026-11-10 12:00:00');

        $this->artisan('gitchronicle:benchmark', ['repository' => $repository->id, '--commits' => 2])
            ->expectsOutputToContain('Incremental vs. from zero — acme/magazin (AI: none)')
            ->expectsOutputToContain('No token estimate')
            ->assertSuccessful();

        $report = json_decode(Storage::disk('local')->get('benchmarks/acme-magazin-20261110-120000.json'), true);
        $this->assertSame('acme/magazin', $report['repository']);
        $this->assertSame('none', $report['ai_driver']);
        $this->assertNull($report['characters_per_token']);
        $this->assertSame(11, $report['baseline']['pages_written']);
        $this->assertSame([$firstChange, $secondChange], array_column($report['commits'], 'sha'));

        [$first, $second] = $report['commits'];
        $this->assertSame(1, $first['files_changed']);
        $this->assertSame(3, $first['modules_affected']);
        $this->assertSame('incremental', $first['incremental']['mode']);
        $this->assertSame(1, $first['incremental']['files_parsed']);
        $this->assertSame(1, $first['incremental']['pages_written']);
        $this->assertSame(10, $first['incremental']['pages_kept']);
        $this->assertSame(13, $first['from_zero']['files_parsed']);
        $this->assertSame(11, $first['from_zero']['pages_written']);
        $this->assertLessThan($first['from_zero']['context_characters'], $first['incremental']['context_characters']);
        $this->assertGreaterThan(0, $first['incremental']['context_characters']);
        $this->assertNull($first['incremental']['tokens_estimated']);
        $this->assertSame(0, $first['incremental']['input_tokens']);
        $this->assertSame(2, $second['files_changed']);
        $this->assertSame(['analyze', 'docs', 'diagrams', 'index'], array_keys($second['incremental']['step_milliseconds']));

        $csv = explode("\n", trim(Storage::disk('local')->get('benchmarks/acme-magazin-20261110-120000.csv')));
        $this->assertCount(3, $csv);
        $this->assertStringStartsWith('sha,files_total,files_changed', $csv[0]);
        $this->assertStringStartsWith("{$firstChange},16,1,9,3,incremental,", $csv[1]);

        // The application's own data is as it was, and the scratch folder is gone.
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(1, Repository::count());
        $this->assertSame($documentsBefore, Document::count());
        $this->assertSame([], array_filter(Storage::disk('local')->directories('benchmarks'), fn (string $directory): bool => str_contains($directory, 'scratch')));
        Storage::disk('local')->assertExists("repositories/{$repository->id}/tools/extra.py");
    }

    public function test_the_token_estimate_uses_the_ratio_measured_on_the_pages_claude_wrote(): void
    {
        $this->skipUnlessAnalyzerIsInstalled();
        $repository = $this->repositoryWithFixtureOrigin();
        $this->pushToOrigin(['tools/extra.py' => "def extra():\n    return 1\n"]);
        $this->process($repository);
        Document::firstWhere('slug', 'tools')->update(['writer' => Document::WRITER_AI, 'context_characters' => 9000, 'input_tokens' => 3000]);
        $this->travelTo('2026-11-10 12:00:00');

        $this->artisan('gitchronicle:benchmark', ['repository' => $repository->id, '--commits' => 1])
            ->expectsOutputToContain('The tokens in parentheses are ESTIMATED: characters / 3')
            ->assertSuccessful();

        $report = json_decode(Storage::disk('local')->get('benchmarks/acme-magazin-20261110-120000.json'), true);
        $this->assertEquals(3.0, $report['characters_per_token']);
        $run = $report['commits'][0]['incremental'];
        $this->assertSame((int) round($run['context_characters'] / 3), $run['tokens_estimated']);
    }

    public function test_refuses_a_repository_that_does_not_exist_or_has_no_clone(): void
    {
        $repository = Repository::factory()->create();

        $this->artisan('gitchronicle:benchmark', ['repository' => 999])->expectsOutputToContain('There is no repository with id 999.')->assertFailed();
        $this->artisan('gitchronicle:benchmark', ['repository' => $repository->id])->expectsOutputToContain('has no clone yet')->assertFailed();
    }
}
