<?php

namespace Tests\Unit;

use App\Documentation\ReadmeDescription;
use PHPUnit\Framework\TestCase;

/**
 * Which README sections the "From the project's own README" part keeps and drops.
 */
class ReadmeDescriptionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $intro = 'Acme turns a folder of invoices into a ledger that your accountant can read without opening a spreadsheet.';

        return [
            'pictures' => [['id' => 'readme-1', 'file' => 'readme-1.png', 'width' => 800, 'height' => 600, 'alt' => 'The ledger', 'mime' => 'image/png', 'section' => 'Demo']],
            'sections' => [
                ['heading' => 'Acme', 'level' => 1, 'text' => $intro, 'pictures' => []],
                ['heading' => 'Features', 'level' => 2, 'text' => "- Reads PDF invoices and receipts from any folder\n- Exports the ledger as CSV for the accountant", 'pictures' => []],
                ['heading' => 'Contributing', 'level' => 2, 'text' => 'Please open an issue before you send a pull request to this project.', 'pictures' => []],
                ['heading' => 'Sponsors', 'level' => 2, 'text' => 'Thanks to everyone who supports the development of this project every month.', 'pictures' => []],
                ['heading' => 'Demo', 'level' => 2, 'text' => '', 'pictures' => ['readme-1']],
                ['heading' => null, 'level' => 1, 'text' => '', 'pictures' => []],
            ],
        ];
    }

    public function test_it_keeps_description_sections_with_their_pictures_and_drops_the_rest(): void
    {
        $sections = ReadmeDescription::sections($this->manifest(), 'Nothing quoted here.', fn (string $file, ?int $width, ?int $height): string => "/p/{$file}#{$width}x{$height}");

        $this->assertSame(['Acme', 'Features', 'Demo'], array_column($sections, 'heading'));
        $this->assertSame('![The ledger](/p/readme-1.png#800x600)', $sections[2]['markdown']);
        $this->assertSame(['readme-1'], $sections[2]['pictures']);
    }

    public function test_it_leaves_out_a_section_the_page_above_already_quotes_word_for_word(): void
    {
        $page = "## What it does\n\nFrom README: “Acme turns a folder of invoices into a ledger that your accountant can read without opening a spreadsheet.”";

        $sections = ReadmeDescription::sections($this->manifest(), $page, fn (string $file): string => $file);

        $this->assertSame(['Features', 'Demo'], array_column($sections, 'heading'));
    }
}
