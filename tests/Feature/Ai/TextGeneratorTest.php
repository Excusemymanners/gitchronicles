<?php

namespace Tests\Feature\Ai;

use App\Ai\AiDisabled;
use App\Ai\BudgetedTextGenerator;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which TextGenerator the app gets for each AI_DRIVER (the binding in AppServiceProvider).
 */
class TextGeneratorTest extends TestCase
{
    /**
     * A real driver always comes wrapped in the daily budget (BudgetedTextGenerator); see BudgetedTextGeneratorTest.
     *
     * @return array<string, array{string, string|null, class-string<TextGenerator>}>
     */
    public static function drivers(): array
    {
        return [
            'Claude Code CLI' => ['claude-code', null, BudgetedTextGenerator::class],
            'API with a key' => ['claude', 'sk-ant-test', BudgetedTextGenerator::class],
            'API without a key' => ['claude', null, NullTextGenerator::class],
            'AI turned off' => ['none', 'sk-ant-test', NullTextGenerator::class],
            'unknown driver' => ['openai', 'sk-ant-test', NullTextGenerator::class],
        ];
    }

    /**
     * @param  class-string<TextGenerator>  $expectedClass
     */
    #[DataProvider('drivers')]
    public function test_the_ai_driver_setting_chooses_the_generator(string $driver, ?string $apiKey, string $expectedClass): void
    {
        config(['gitchronicle.ai_driver' => $driver, 'gitchronicle.anthropic.api_key' => $apiKey]);

        $this->assertInstanceOf($expectedClass, app(TextGenerator::class));
    }

    public function test_tests_run_with_ai_turned_off_so_every_call_falls_back_to_the_offline_mode(): void
    {
        $this->expectException(AiDisabled::class);

        app(TextGenerator::class)->generate('Instrucțiuni', 'Întrebare');
    }
}
