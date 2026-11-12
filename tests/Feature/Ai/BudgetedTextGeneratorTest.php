<?php

namespace Tests\Feature\Ai;

use App\Ai\AiException;
use App\Ai\BudgetedTextGenerator;
use App\Ai\TextGenerator;
use App\Documentation\AiDocumentationWriter;
use App\Documentation\OverviewContext;
use App\Documentation\SourceLinks;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The site's daily budget of Claude calls (security audit AI-LAYER-02): every real driver is wrapped
 * in BudgetedTextGenerator, which refuses calls over config('gitchronicle.limits.ai_daily_calls').
 */
class BudgetedTextGeneratorTest extends TestCase
{
    public function test_calls_are_sent_until_the_daily_budget_is_used_up_then_refused_as_retryable(): void
    {
        $budgeted = new BudgetedTextGenerator($fake = new FakeTextGenerator(['Text.']), dailyCalls: 2);

        $budgeted->generate('Instrucțiuni', 'Întrebarea 1');
        $budgeted->generate('Instrucțiuni', 'Întrebarea 2');

        try {
            $budgeted->generate('Instrucțiuni', 'Întrebarea 3');
            $this->fail('The third call must be refused.');
        } catch (AiException $exception) {
            $this->assertSame(BudgetedTextGenerator::message(), $exception->getMessage());
            $this->assertTrue($exception->retryable);
        }

        $fake->assertSentCount(2);
    }

    public function test_the_budget_is_counted_for_the_whole_site_and_starts_again_the_next_day(): void
    {
        $this->travelTo('2026-11-11 23:00:00');
        (new BudgetedTextGenerator(new FakeTextGenerator, dailyCalls: 1))->generate('Instrucțiuni', 'Prima');

        // Another instance (another request or worker) shares the same counter.
        $secondWorker = new BudgetedTextGenerator($fake = new FakeTextGenerator, dailyCalls: 1);
        $this->assertThrows(fn () => $secondWorker->generate('Instrucțiuni', 'A doua'), AiException::class, BudgetedTextGenerator::message());

        $this->travelTo('2026-11-12 00:30:00');
        $secondWorker->generate('Instrucțiuni', 'Ziua următoare');

        $fake->assertSentCount(1);
    }

    public function test_the_timeout_asked_by_the_caller_reaches_the_driver(): void
    {
        $budgeted = new BudgetedTextGenerator($fake = new FakeTextGenerator, dailyCalls: 5);

        $budgeted->generate('Instrucțiuni', 'Întrebare', 60);

        $this->assertSame(60, $fake->requests[0]['timeout']);
    }

    public function test_the_real_drivers_are_wrapped_in_the_budget_from_config(): void
    {
        config(['gitchronicle.ai_driver' => 'claude-code', 'gitchronicle.limits.ai_daily_calls' => 1]);
        Cache::put('ai-calls:'.now()->toDateString(), 1);

        $generator = app(TextGenerator::class);

        $this->assertInstanceOf(BudgetedTextGenerator::class, $generator);
        // With a budget of 0 the CLI is never started: the call is refused before it is sent.
        $this->assertThrows(fn () => $generator->generate('Instrucțiuni', 'Întrebare'), AiException::class, BudgetedTextGenerator::message());
    }

    public function test_a_budget_of_zero_means_no_cap(): void
    {
        $budgeted = new BudgetedTextGenerator($fake = new FakeTextGenerator, dailyCalls: 0);

        foreach (range(1, 300) as $call) {
            $budgeted->generate('s', 'u');
        }

        $fake->assertSentCount(300);
    }

    public function test_the_message_tells_the_owner_what_to_do_in_both_languages(): void
    {
        $this->assertStringContainsString('regenerate tomorrow or raise AI_DAILY_CALLS', BudgetedTextGenerator::message());

        app()->setLocale('ro');

        $this->assertStringContainsString('AI_DAILY_CALLS', BudgetedTextGenerator::message());
        $this->assertStringContainsString('șabloane', BudgetedTextGenerator::message());
    }

    public function test_a_documentation_page_over_the_budget_falls_back_like_any_ai_error(): void
    {
        Cache::put('ai-calls:'.now()->toDateString(), 1);
        $writer = new AiDocumentationWriter(new BudgetedTextGenerator($fake = new FakeTextGenerator(['# Pagina']), dailyCalls: 1));

        $this->assertThrows(
            fn () => $writer->readme(...$this->emptyReadmeContext()),
            AiException::class,
            BudgetedTextGenerator::message(),
        );
        $fake->assertSentCount(0);
    }

    /**
     * The smallest overview context and links, enough to ask for a README.
     *
     * @return array{0: OverviewContext, 1: SourceLinks}
     */
    private function emptyReadmeContext(): array
    {
        return [
            new OverviewContext(
                repository: 'ana/magazin',
                name: 'magazin',
                fileCount: 0,
                lineCount: 0,
                languages: [],
                readme: null,
                manifests: [],
                requirements: [],
                commands: [],
                topFolders: [],
                modules: [],
                dependencies: [],
                dependencyCount: 0,
                entryPoints: [],
                tests: null,
                license: null,
            ),
            new SourceLinks('ana', 'magazin', 'abc123'),
        ];
    }
}
