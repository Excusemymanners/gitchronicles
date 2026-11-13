<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\StepEvidence;
use PHPUnit\Framework\TestCase;

/**
 * The line cited for an arrow is the best backing link, not the first one found: never a guard line, a
 * declaration or a test, the kind that matches the verb, the code path of the earlier steps, the module the
 * part is named after, the driver the configuration selects.
 */
class StepEvidenceTest extends TestCase
{
    public function test_a_guard_line_is_never_cited_when_a_statement_that_does_the_work_exists(): void
    {
        $ranked = StepEvidence::rank([
            $this->link('app/Jobs/CloneRepository.php', 207, 'runs', 'return ! app(TextGenerator::class) instanceof NullTextGenerator'),
            $this->link('app/Jobs/GenerateDocumentation.php', 90, 'dispatch', 'GenerateDocumentation::dispatch($repository);'),
        ], $this->context('asks for docs'));

        $this->assertSame(['app/Jobs/GenerateDocumentation.php'], array_column($ranked, 'from'));
        $this->assertTrue(StepEvidence::isGuard("if (! Schema::hasTable('documents')) {"));
        $this->assertTrue(StepEvidence::isGuard('use Anthropic\Beta\Messages\BetaTextBlock;'));
    }

    public function test_a_link_from_the_part_named_after_the_module_beats_a_link_from_another_module(): void
    {
        $ranked = StepEvidence::rank([
            $this->link('app/Assistant/AnswerQuestion.php', 12, 'stores', 'Document::query()->create($row);', 'app/Assistant', null, 'store:database'),
            $this->link('app/Documentation/DocumentationGenerator.php', 30, 'stores', 'Document::query()->create($row);', 'app/Documentation', null, 'store:database'),
        ], [...$this->context('saves the results', 'data'), 'from_label' => 'Documentation writer']);

        $this->assertSame('app/Documentation/DocumentationGenerator.php', $ranked[0]['from']);
    }

    public function test_a_write_beats_a_read_for_a_saving_verb_and_a_read_is_not_a_backing_for_it_alone(): void
    {
        $read = $this->link('app/Documentation/Reader.php', 5, 'stores', 'Document::where("slug", $slug)->first();', 'app/Documentation', null, 'store:database');
        $write = $this->link('app/Documentation/Writer.php', 9, 'stores', '$document->save();', 'app/Documentation', null, 'store:database');

        $this->assertSame('app/Documentation/Writer.php', StepEvidence::rank([$read, $write], $this->context('saves the results', 'data'))[0]['from']);
        $this->assertSame([], StepEvidence::rank([$read], $this->context('saves the results', 'data')));
        $this->assertSame('app/Documentation/Reader.php', StepEvidence::rank([$write, $read], $this->context('reads documentation', 'data'))[0]['from']);
    }

    public function test_the_class_an_earlier_step_reached_beats_an_unrelated_class(): void
    {
        $ranked = StepEvidence::rank([
            $this->link('app/Console/Commands/Other.php', 3, 'dispatch', 'Other::dispatch();'),
            $this->link('app/Jobs/ProcessRepository.php', 40, 'dispatch', 'AnalyzeRepository::dispatch();'),
        ], [...$this->context('asks to read code'), 'reached' => ['app/Jobs/ProcessRepository.php' => true]]);

        $this->assertSame('app/Jobs/ProcessRepository.php', $ranked[0]['from']);
    }

    public function test_only_the_driver_the_configuration_selects_is_cited(): void
    {
        $candidates = [
            $this->link('app/Ai/ClaudeApiGenerator.php', 56, 'calls', '$this->client->beta->messages->create($parameters);', 'app/Ai', null, 'service:anthropic'),
            $this->link('app/Ai/ClaudeCodeGenerator.php', 57, 'runs', 'Process::run($command);', 'app/Ai', null, 'tool:claude'),
        ];

        $this->assertSame('app/Ai/ClaudeCodeGenerator.php', StepEvidence::rank($candidates, [...$this->context('asks to write', 'outside'), 'driver' => 'ClaudeCodeGenerator'])[0]['from']);
        $this->assertSame('app/Ai/ClaudeApiGenerator.php', StepEvidence::rank($candidates, [...$this->context('asks to write', 'outside'), 'driver' => 'ClaudeApiGenerator'])[0]['from']);
    }

    public function test_an_arrow_with_nothing_but_guards_declarations_and_tests_has_no_backing(): void
    {
        $ranked = StepEvidence::rank([
            $this->link('app/Jobs/Check.php', 10, 'runs', 'if (app(Check::class)->exists()) {'),
            $this->link('app/Jobs/Handle.php', 20, 'reference', 'public function handle(Analyzer $analyzer): void'),
            $this->link('tests/Feature/JobTest.php', 30, 'dispatch', 'Job::dispatch();'),
            $this->link('app/Console/Commands/BenchmarkIncremental.php', 40, 'runs', "->run(['git', 'log'])"),
        ], $this->context('asks for docs'));

        $this->assertSame([], $ranked);
    }

    public function test_the_same_facts_always_give_the_same_order(): void
    {
        $candidates = [
            $this->link('app/Jobs/B.php', 2, 'dispatch', 'B::dispatch();'),
            $this->link('app/Jobs/A.php', 9, 'dispatch', 'A::dispatch();'),
            $this->link('app/Jobs/A.php', 3, 'dispatch', 'A::dispatch(1);'),
        ];

        $this->assertSame(StepEvidence::rank($candidates, $this->context('starts processing')), StepEvidence::rank(array_reverse($candidates), $this->context('starts processing')));
        $this->assertSame(3, StepEvidence::rank($candidates, $this->context('starts processing'))[0]['line']);
    }

    /**
     * @return array{from: string, to: string|null, line: int, kind: string, excerpt: string, from_module: string, to_module: string|null, target: string|null}
     */
    private function link(string $from, int $line, string $kind, string $excerpt, string $fromModule = 'app/Jobs', ?string $toModule = 'app/Analysis', ?string $target = null): array
    {
        return ['from' => $from, 'to' => $toModule === null ? null : $toModule.'/Target.php', 'line' => $line, 'kind' => $kind, 'excerpt' => $excerpt, 'from_module' => $fromModule, 'to_module' => $toModule, 'target' => $target];
    }

    /**
     * @return array{verb: string, from_label: string, to_label: string, to_type: string, reached: array<string, mixed>, driver: string|null}
     */
    private function context(string $verb, string $toType = 'code'): array
    {
        return ['verb' => $verb, 'from_label' => 'Background jobs', 'to_label' => 'Target', 'to_type' => $toType, 'reached' => [], 'driver' => null];
    }
}
