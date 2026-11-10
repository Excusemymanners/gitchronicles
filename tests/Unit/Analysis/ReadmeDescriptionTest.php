<?php

namespace Tests\Unit\Analysis;

use App\Analysis\ReadmeDescription;
use PHPUnit\Framework\TestCase;

class ReadmeDescriptionTest extends TestCase
{
    public function test_returns_the_first_paragraph_after_the_logo_heading_and_badges(): void
    {
        $markdown = <<<'MD'
            <img src="public/logo.png" alt="" width="96">

            # GitChronicle

            [![Tests](https://github.com/acme/app/actions/workflows/tests.yml/badge.svg)](https://github.com/acme/app/actions)
            ![Logo](logo.png)

            ---

            Asistent AI care documentează automat un repository GitHub.

            Al doilea paragraf.
            MD;

        $description = ReadmeDescription::fromMarkdown($markdown);

        $this->assertSame('Asistent AI care documentează automat un repository GitHub.', $description);
    }

    public function test_turns_markdown_into_plain_text_on_one_line(): void
    {
        $markdown = "A **fast** framework built on [Laravel](https://laravel.com)\nwith `queues` and <em>React</em>.";

        $description = ReadmeDescription::fromMarkdown($markdown);

        $this->assertSame('A fast framework built on Laravel with queues and React.', $description);
    }

    public function test_skips_a_code_block_before_the_text(): void
    {
        $markdown = "# Tool\n\n```bash\nnpm install tool\n```\n\nA tool for people.";

        $description = ReadmeDescription::fromMarkdown($markdown);

        $this->assertSame('A tool for people.', $description);
    }

    /**
     * Each "cuvânt " has 7 characters, so the first 300 characters end right after the 43rd word.
     */
    public function test_cuts_a_long_paragraph_at_a_word_and_ends_it_with_an_ellipsis(): void
    {
        $markdown = str_repeat('cuvânt ', 100);

        $description = ReadmeDescription::fromMarkdown($markdown);

        $this->assertSame(trim(str_repeat('cuvânt ', 43)).'…', $description);
    }

    public function test_returns_null_when_the_readme_has_only_headings_and_images(): void
    {
        $markdown = "# Title\n\n![screenshot](screen.png)\n\n## Install";

        $description = ReadmeDescription::fromMarkdown($markdown);

        $this->assertNull($description);
    }
}
