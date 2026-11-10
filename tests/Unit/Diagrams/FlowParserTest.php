<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\FlowParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FlowParserTest extends TestCase
{
    /**
     * The files the context showed: path => number of lines.
     */
    private const FILE_LINES = ['routes/web.php' => 27, 'app/Http/Controllers/RepositoryController.php' => 99];

    public function test_keeps_a_valid_flow_with_a_key_made_from_its_title(): void
    {
        $answer = $this->answer([$this->flow()]);

        $result = FlowParser::parse($answer, self::FILE_LINES);

        $this->assertSame([[
            'key' => 'adaugarea-unui-repository',
            'title' => 'Adăugarea unui repository',
            'explanation' => 'Utilizatorul trimite adresa, iar controllerul creează repository-ul.',
            'mermaid' => "flowchart TD\n  A[\"Formularul\"] --> B[\"RepositoryController::store\"]\n",
            'sources' => [['path' => 'routes/web.php', 'start_line' => 12, 'end_line' => 15]],
        ]], $result['flows']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_reads_the_json_inside_code_fences_or_a_sentence(): void
    {
        $answer = "Iată fluxurile:\n```json\n".$this->answer([$this->flow()])."\n```";

        $result = FlowParser::parse($answer, self::FILE_LINES);

        $this->assertCount(1, $result['flows']);
    }

    public function test_an_answer_that_is_not_json_gives_no_flows(): void
    {
        $result = FlowParser::parse('Nu pot răspunde.', self::FILE_LINES);

        $this->assertSame([], $result['flows']);
        $this->assertSame(['the answer is not JSON with a "flows" list'], $result['rejected']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeMermaid(): array
    {
        return [
            'a click link' => ["flowchart TD\n  A[\"x\"] --> B[\"y\"]\n  click A \"https://example.com\""],
            'a click callback' => ["flowchart TD\n  A[\"x\"]\n  click A call alert()"],
            'href' => ["flowchart TD\n  A[\"<a href='https://example.com'>x</a>\"]"],
            'javascript: in a label' => ["flowchart TD\n  A[\"javascript:alert(1)\"]"],
            'an HTML label' => ["flowchart TD\n  A[\"<b>x</b>\"]"],
            'an init directive after the header' => ["flowchart TD\n  %%{init: {'securityLevel': 'loose'}}%%\n  A[\"x\"]"],
            'a front matter block' => ["flowchart TD\n---\nconfig:\n  securityLevel: loose\n---\n  A[\"x\"]"],
            'an image shape' => ["flowchart TD\n  A@{ img: \"https://example.com/x.png\" }"],
            'a style with a url' => ["flowchart TD\n  A[\"x\"]\n  style A fill:url(https://example.com/x)"],
            'a class definition' => ["flowchart TD\n  A[\"x\"]\n  classDef red fill:#f00"],
        ];
    }

    #[DataProvider('unsafeMermaid')]
    public function test_rejects_a_flow_whose_mermaid_can_run_code_open_links_or_change_the_page(string $mermaid): void
    {
        $answer = $this->answer([$this->flow(['mermaid' => $mermaid])]);

        $result = FlowParser::parse($answer, self::FILE_LINES);

        $this->assertSame([], $result['flows']);
        $this->assertStringStartsWith('flow 1: forbidden Mermaid syntax', $result['rejected'][0]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFlows(): array
    {
        return [
            'another diagram type' => [['mermaid' => "sequenceDiagram\n  A->>B: x"], 'flow 1: not a "flowchart TD" diagram'],
            'a flowchart from left to right' => [['mermaid' => "flowchart LR\n  A --> B"], 'flow 1: not a "flowchart TD" diagram'],
            'an init directive before the header' => [['mermaid' => "%%{init: {'securityLevel': 'loose'}}%%\nflowchart TD\n  A[\"x\"]"], 'flow 1: not a "flowchart TD" diagram'],
            'no title' => [['title' => ''], 'flow 1: missing title'],
            'an explanation that is not text' => [['explanation' => ['x']], 'flow 1: missing explanation'],
            'a title that is too long' => [['title' => str_repeat('x', 101)], 'flow 1: title too long'],
            'sources outside the files of the context' => [['sources' => [['path' => '.env', 'start_line' => 1, 'end_line' => 2]]], 'flow 1: no valid source lines'],
            'lines after the end of the file' => [['sources' => [['path' => 'routes/web.php', 'start_line' => 20, 'end_line' => 40]]], 'flow 1: no valid source lines'],
            'lines in the wrong order' => [['sources' => [['path' => 'routes/web.php', 'start_line' => 9, 'end_line' => 3]]], 'flow 1: no valid source lines'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidFlows')]
    public function test_rejects_an_invalid_flow_with_the_reason(array $overrides, string $reason): void
    {
        $answer = $this->answer([$this->flow($overrides)]);

        $result = FlowParser::parse($answer, self::FILE_LINES);

        $this->assertSame([], $result['flows']);
        $this->assertSame([$reason], $result['rejected']);
    }

    public function test_keeps_only_the_valid_sources_of_a_flow(): void
    {
        $answer = $this->answer([$this->flow(['sources' => [
            ['path' => 'routes/web.php', 'start_line' => 12, 'end_line' => 15],
            ['path' => 'app/Missing.php', 'start_line' => 1, 'end_line' => 5],
            ['path' => 'app/Http/Controllers/RepositoryController.php', 'start_line' => '47', 'end_line' => 73],
        ]])]);

        $result = FlowParser::parse($answer, self::FILE_LINES);

        $this->assertSame([['path' => 'routes/web.php', 'start_line' => 12, 'end_line' => 15]], $result['flows'][0]['sources']);
    }

    public function test_keeps_at_most_three_flows_with_unique_keys(): void
    {
        $answer = $this->answer(array_fill(0, 4, $this->flow()));

        $result = FlowParser::parse($answer, self::FILE_LINES);

        $this->assertSame(
            ['adaugarea-unui-repository', 'adaugarea-unui-repository-2', 'adaugarea-unui-repository-3'],
            array_column($result['flows'], 'key'),
        );
    }

    /**
     * A valid flow, with some fields replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function flow(array $overrides = []): array
    {
        return [
            'title' => 'Adăugarea unui repository',
            'explanation' => 'Utilizatorul trimite adresa, iar controllerul creează repository-ul.',
            'mermaid' => "flowchart TD\n  A[\"Formularul\"] --> B[\"RepositoryController::store\"]",
            'sources' => [['path' => 'routes/web.php', 'start_line' => 12, 'end_line' => 15]],
            ...$overrides,
        ];
    }

    /**
     * Claude's answer with these flows.
     *
     * @param  list<array<string, mixed>>  $flows
     */
    private function answer(array $flows): string
    {
        return json_encode(['flows' => $flows], JSON_UNESCAPED_UNICODE);
    }
}
