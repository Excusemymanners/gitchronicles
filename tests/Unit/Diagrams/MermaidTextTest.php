<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\MermaidText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MermaidTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function textsAndTheirEscapedForm(): array
    {
        return [
            'double quotes cannot close the label' => ['Spune "salut"', 'Spune #quot;salut#quot;'],
            'an HTML tag stays text' => ['<img src=x onerror=alert(1)>', '#lt;img src=x onerror=alert(1)#gt;'],
            'ampersand and hash become entity codes' => ['A & B #1', 'A #amp; B #35;1'],
            'a backtick cannot start a Markdown string' => ['`cod`', '#96;cod#96;'],
            'a backslash is neutralized so a YAML label cannot rebuild a tag' => ['a\\b', 'a#92;b'],
            'brackets and braces stay as they are' => ['[x] (y) {z}', '[x] (y) {z}'],
            'diacritics and emoji stay as they are' => ['Logica aplicației 🚀', 'Logica aplicației 🚀'],
            'newlines and tabs become one space' => ["linia 1\n\tlinia 2", 'linia 1 linia 2'],
        ];
    }

    #[DataProvider('textsAndTheirEscapedForm')]
    public function test_escapes_text_for_a_quoted_label(string $text, string $escaped): void
    {
        $this->assertSame($escaped, MermaidText::escape($text));
    }

    public function test_the_yaml_escape_payload_of_the_audit_cannot_rebuild_html_in_a_label(): void
    {
        $escaped = MermaidText::escape('\\x3ca href=https:\\x2f\\x2fevil.example\\x2fphish\\x3eCLICK-INJ\\x3c\\x2fa\\x3e');

        $this->assertStringNotContainsString('\\', $escaped);
        $this->assertStringStartsWith('#92;x3ca href=', $escaped);
    }

    public function test_an_entity_code_is_written_once_and_not_escaped_again(): void
    {
        $this->assertSame('#35;quot;', MermaidText::escape('#quot;'));
    }

    public function test_shortens_a_long_line_with_an_ellipsis(): void
    {
        $escaped = MermaidText::escape(str_repeat('a', 100));

        $this->assertSame(str_repeat('a', 90).'…', $escaped);
    }

    public function test_a_label_with_details_has_a_bold_title_and_one_line_per_detail(): void
    {
        $label = MermaidText::label('Http', ['app/Http', 'RepositoryController']);

        $this->assertSame('<b>Http</b><br>app/Http<br>RepositoryController', $label);
    }

    public function test_a_label_without_details_is_only_the_escaped_title(): void
    {
        $this->assertSame('app/#quot;x#quot;', MermaidText::label('app/"x"'));
    }
}
