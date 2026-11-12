<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\ReadingParser;
use App\Enums\DiagramType;
use PHPUnit\Framework\TestCase;

/**
 * The checks that every reading written by Claude must pass: names that exist in the diagram and
 * numbers that match the findings.
 */
class ReadingParserTest extends TestCase
{
    /**
     * @return list<array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}>
     */
    private function diagrams(): array
    {
        return [[
            'type' => DiagramType::Component,
            'key' => '',
            'mermaid' => '',
            'meta' => [
                'nodes' => ['c1' => 'app/Http', 'c2' => 'app/Models'],
                'classes' => ['c2' => ['User']],
                'evidence' => [['from' => 'c1', 'to' => 'c2', 'from_label' => 'app/Http', 'to_label' => 'app/Models', 'imports' => 7, 'examples' => [], 'nodes' => ['c1', 'c2']]],
                'findings' => [
                    'core' => [['module' => 'app/Models', 'modules' => 1, 'imports' => 7]],
                    'coverage' => ['shown_modules' => 2, 'total_modules' => 4, 'shown_lines' => 300, 'total_lines' => 400, 'total_imports' => 9, 'undrawn_imports' => 2],
                ],
            ],
        ]];
    }

    private function answer(string $reading, string $worth = 'It is worth knowing.'): string
    {
        return json_encode(['diagrams' => ['component' => ['reading' => $reading, 'worth' => $worth]]]);
    }

    public function test_accepts_a_reading_whose_names_and_numbers_are_in_the_diagram(): void
    {
        $parsed = ReadingParser::parse($this->answer('A request enters `app/Http` and reaches `app/Models` through 7 imports.', 'The diagram shows 75% of the code, and `User` is the main class.'), $this->diagrams(), 'acme');

        $this->assertSame([], $parsed['rejected']);
        $this->assertSame('A request enters `app/Http` and reaches `app/Models` through 7 imports.', $parsed['readings']['component']['reading']);
    }

    public function test_rejects_a_module_name_that_is_not_in_the_diagram(): void
    {
        $parsed = ReadingParser::parse($this->answer('Everything goes through `app/Jobs`.'), $this->diagrams(), 'acme');

        $this->assertSame([], $parsed['readings']);
        $this->assertStringContainsString('app/Jobs', $parsed['rejected']['component']);
    }

    public function test_rejects_a_number_that_does_not_match_the_findings(): void
    {
        $parsed = ReadingParser::parse($this->answer('`app/Http` imports `app/Models` in 12 places.'), $this->diagrams(), 'acme');

        $this->assertSame([], $parsed['readings']);
        $this->assertStringContainsString('12', $parsed['rejected']['component']);
    }

    public function test_numbers_inside_a_name_are_not_checked(): void
    {
        $parsed = ReadingParser::parse($this->answer('Pages live in `app/Http`.', 'The `User` class is imported 7 times.'), $this->diagrams(), 'acme');

        $this->assertArrayHasKey('component', $parsed['readings']);
    }

    public function test_rejects_html_an_empty_field_and_an_answer_that_is_not_json(): void
    {
        $this->assertArrayHasKey('component', ReadingParser::parse($this->answer('<b>Bold</b> text.'), $this->diagrams(), 'acme')['rejected']);
        $this->assertArrayHasKey('component', ReadingParser::parse($this->answer('Fine.', ''), $this->diagrams(), 'acme')['rejected']);
        $this->assertArrayHasKey('*', ReadingParser::parse('Sorry, I cannot.', $this->diagrams(), 'acme')['rejected']);
    }

    public function test_finds_the_json_inside_a_code_block(): void
    {
        $parsed = ReadingParser::parse("```json\n".$this->answer('`app/Http` calls `app/Models`.')."\n```", $this->diagrams(), 'acme');

        $this->assertArrayHasKey('component', $parsed['readings']);
    }

    public function test_a_technology_name_with_a_version_may_be_named_with_or_without_it(): void
    {
        $diagrams = [[
            'type' => DiagramType::Context,
            'key' => '',
            'mermaid' => '',
            'meta' => ['externals' => [['name' => 'Stripe 16', 'icon' => null, 'source' => 'composer.json']]],
        ]];
        $answer = json_encode(['diagrams' => ['context' => ['reading' => '`acme` sends payments to `Stripe`.', 'worth' => 'The `Stripe 16` SDK is the only external system.']]]);

        $this->assertArrayHasKey('context', ReadingParser::parse($answer, $diagrams, 'acme')['readings']);
    }
}
