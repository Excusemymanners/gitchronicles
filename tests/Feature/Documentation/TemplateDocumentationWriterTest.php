<?php

namespace Tests\Feature\Documentation;

use App\Documentation\ContextBuilder;
use App\Documentation\ModuleContext;
use App\Documentation\OverviewContext;
use App\Documentation\SourceLinks;
use App\Documentation\TemplateDocumentationWriter;
use App\Models\Document;
use Tests\TestCase;

/**
 * The pages written without AI: fixed sections, links to the exact lines on GitHub, and only facts
 * from the context (a README without evidence for a section leaves that section out).
 */
class TemplateDocumentationWriterTest extends TestCase
{
    public function test_module_page_has_the_five_sections_and_links_each_component_to_its_lines(): void
    {
        $page = (new TemplateDocumentationWriter)->module($this->moduleContext(), $this->links());

        $this->assertSame(Document::WRITER_TEMPLATE, $page->writer);
        $this->assertSame(0, $page->inputTokens);
        $this->assertSame(
            ['## Role', '## How it works', '## Main components', '## Dependencies', '## Files'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringStartsWith('# Module `src/Models`', $page->markdown);
        $this->assertStringContainsString(
            '**[`Product`](https://github.com/acme/magazin/blob/abc123/src/Models/Product.php#L3-L9)** — class in `Product.php`, lines 3–9. Members: `price`.',
            $page->markdown,
        );
        $this->assertStringContainsString('[`Plan tematic.php`](https://github.com/acme/magazin/blob/abc123/src/Models/Plan%20tematic.php)', $page->markdown);
        $this->assertStringContainsString('- module `src/Http` (1 import)', $page->markdown);
    }

    public function test_module_page_keeps_names_with_markdown_characters_as_code(): void
    {
        $context = $this->moduleContext(symbols: [
            ['path' => 'src/Models/Product.php', 'kind' => 'method', 'name' => '__init__', 'start_line' => 1, 'end_line' => 2],
        ]);

        $page = (new TemplateDocumentationWriter)->module($context, $this->links());

        $this->assertStringContainsString('**[`__init__`](', $page->markdown);
    }

    public function test_overview_without_problem_or_setup_has_no_problem_and_no_getting_started_section(): void
    {
        $page = (new TemplateDocumentationWriter)->overview($this->overviewContext(), $this->links());

        $this->assertSame(
            ['## What the project does', '## How it solves it', '## What to read next', '## Technologies', '## Modules'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringContainsString('The repository has no README in its root folder', $page->markdown);
        $this->assertStringNotContainsString('problem', strtolower(implode(' ', preg_grep('/\A## /', explode("\n", $page->markdown)))));
        $this->assertStringContainsString('| [`src/Models`](/repositories/1/docs/src-models) | 2 | 14 |', $page->markdown);
        $this->assertStringNotContainsString('| `storage` |', $page->markdown);
        $this->assertStringEndsWith("1 smaller module has no page of its own.\n", $page->markdown);
        $this->assertStringContainsString('- [`src/Models`](/repositories/1/docs/src-models) — 14 lines of code.', $page->markdown);
    }

    public function test_overview_with_a_stated_problem_and_setup_steps_follows_the_order_of_a_newcomer(): void
    {
        $readme = [
            'path' => 'README.md', 'description' => 'A small shop.', 'features' => [],
            'problem' => 'Shops lose carts when the session ends.', 'setup_commands' => ['cp .env.example .env', 'composer install'],
            'excerpt' => '', 'truncated' => false,
        ];
        $context = $this->overviewContext(
            commands: [
                ['command' => 'composer install', 'purpose' => 'install', 'source' => 'composer.json', 'detail' => null],
                ['command' => 'composer run dev', 'purpose' => 'run', 'source' => 'composer.json', 'detail' => '@php artisan serve'],
            ],
            requirements: [['name' => 'PHP', 'version' => '^8.3', 'source' => 'composer.json']],
            readme: $readme,
            entryPoints: [['path' => 'src/Models/boot.php', 'kind' => 'entry file']],
            diagramsPage: '/repositories/1/diagrams',
        );

        $page = (new TemplateDocumentationWriter)->overview($context, $this->links());

        $this->assertSame(
            ['## What the project does', '## The problem it addresses', '## How it solves it', '## Getting started', '## What to read next', '## Technologies', '## Modules'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringContainsString('Shops lose carts when the session ends.', $page->markdown);
        $this->assertStringContainsString("```bash\ncp .env.example .env\ncomposer install\n```", $page->markdown);
        $this->assertStringContainsString('- `composer run dev` — starts the project (from `composer.json`), runs `@php artisan serve`', $page->markdown);
        $this->assertStringNotContainsString('`composer install` — installs', $page->markdown);
        $this->assertStringContainsString('[Diagrams page](/repositories/1/diagrams)', $page->markdown);
        $this->assertStringContainsString('holds the entry point `src/Models/boot.php`', $page->markdown);
    }

    public function test_the_modules_table_has_no_closing_sentence_when_every_module_has_a_page(): void
    {
        $context = $this->overviewContext();
        $modules = [['path' => 'src/Models', 'files' => 2, 'lines' => 14, 'page' => '/repositories/1/docs/src-models', 'reason' => null]];
        $context = new OverviewContext(...[...$context->toArray(), 'modules' => $modules]);

        $page = (new TemplateDocumentationWriter)->overview($context, $this->links());

        $this->assertStringNotContainsString('no page of its own', $page->markdown);
    }

    public function test_readme_leaves_out_every_section_without_evidence(): void
    {
        $page = (new TemplateDocumentationWriter)->readme($this->overviewContext(), $this->links());

        $this->assertSame(
            ['## How it is built', '## Where to go next'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringContainsString('**Technologies**', $page->markdown);
        $this->assertStringStartsWith("# magazin\n\nThe repository `acme/magazin` has 3 files with 15 lines, written mostly in PHP.", $page->markdown);
        $this->assertStringNotContainsString('docs/src-models', $page->markdown);
        $this->assertStringContainsString('[`src/Models`](https://github.com/acme/magazin/tree/abc123/src/Models)', $page->markdown);
    }

    public function test_readme_follows_the_order_what_problem_quick_start_built_next(): void
    {
        $context = $this->overviewContext(
            commands: [['command' => 'composer install', 'purpose' => 'install', 'source' => 'composer.json', 'detail' => null]],
            readme: ['path' => 'README.md', 'description' => 'A small shop.', 'features' => ['Cart'], 'problem' => 'Carts get lost.', 'setup_commands' => [], 'excerpt' => '', 'truncated' => false],
            diagramsPage: '/repositories/1/diagrams',
        );

        $page = (new TemplateDocumentationWriter)->readme($context, $this->links());

        $this->assertSame(
            ['## The problem it addresses', '## Features', '## Quick start', '## How it is built', '## Where to go next'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringContainsString("# Installs the dependencies (from composer.json)\ncomposer install", $page->markdown);
        $this->assertStringContainsString('[Diagrams page](/repositories/1/diagrams)', $page->markdown);
    }

    public function test_readme_of_a_project_without_code_or_manifests_has_no_technologies_section(): void
    {
        $context = new OverviewContext(
            repository: 'octocat/hello-world', name: 'hello-world', readme: null, fileCount: 1, lineCount: 1,
            languages: [['name' => ContextBuilder::OTHER_LANGUAGE, 'files' => 1, 'lines' => 1]], manifests: [],
            topFolders: [['path' => '/', 'files' => 1, 'lines' => 1, 'languages' => []]],
            modules: [['path' => '/', 'files' => 1, 'lines' => 1, 'page' => null, 'reason' => 'no code in a recognized language']],
            dependencies: [], dependencyCount: 0, entryPoints: [], commands: [], requirements: [], tests: null, license: null,
        );

        $page = (new TemplateDocumentationWriter)->readme($context, $this->links());

        $this->assertSame(['## How it is built', '## Where to go next'], array_values(preg_grep('/\A## /', explode("\n", $page->markdown))));
    }

    public function test_readme_has_install_run_test_and_license_sections_only_from_the_found_facts(): void
    {
        $context = $this->overviewContext(
            commands: [
                ['command' => 'composer install', 'purpose' => 'install', 'source' => 'composer.json', 'detail' => null],
                ['command' => 'composer run dev', 'purpose' => 'run', 'source' => 'composer.json', 'detail' => '@php artisan serve'],
                ['command' => 'composer run test', 'purpose' => 'test', 'source' => 'composer.json', 'detail' => 'phpunit'],
            ],
            requirements: [['name' => 'PHP', 'version' => '^8.3', 'source' => 'composer.json']],
            tests: ['frameworks' => [['name' => 'PHPUnit', 'source' => 'phpunit.xml']], 'test_files' => 4],
            license: ['name' => 'MIT', 'source' => 'LICENSE'],
        );

        $page = (new TemplateDocumentationWriter)->readme($context, $this->links());

        $this->assertStringContainsString("## Quick start\n\n```bash\ngit clone https://github.com/acme/magazin.git\ncd magazin\n# Installs the dependencies (from composer.json)\ncomposer install\n# Starts the project (from composer.json)\ncomposer run dev\n```", $page->markdown);
        $this->assertStringContainsString("- PHPUnit (from `phpunit.xml`)\n- 4 test files\n\n```bash\ncomposer run test\n```", $page->markdown);
        $this->assertStringContainsString("## License\n\nMIT (from `LICENSE`).", $page->markdown);
        $this->assertStringContainsString('- PHP `^8.3` (from `composer.json`)', $page->markdown);
    }

    /**
     * The links of "acme/magazin" at the commit "abc123".
     */
    private function links(): SourceLinks
    {
        return new SourceLinks('acme', 'magazin', 'abc123');
    }

    /**
     * The context of the module "src/Models": Product (with the method price) and a file whose name has a space.
     *
     * @param  list<array{path: string, kind: string, name: string, start_line: int, end_line: int}>|null  $symbols
     */
    private function moduleContext(?array $symbols = null): ModuleContext
    {
        return new ModuleContext(
            repository: 'acme/magazin',
            path: 'src/Models',
            fileCount: 2,
            lineCount: 14,
            folderReadme: null,
            files: [
                ['path' => 'src/Models/Plan tematic.php', 'language' => 'PHP', 'lines' => 5],
                ['path' => 'src/Models/Product.php', 'language' => 'PHP', 'lines' => 9],
            ],
            unlistedFileCount: 0,
            symbols: $symbols ?? [
                ['path' => 'src/Models/Product.php', 'kind' => 'class', 'name' => 'Product', 'start_line' => 3, 'end_line' => 9],
                ['path' => 'src/Models/Product.php', 'kind' => 'method', 'name' => 'price', 'start_line' => 5, 'end_line' => 8],
            ],
            unlistedSymbolCount: 0,
            usesModules: [],
            usedByModules: [['path' => 'src/Http', 'weight' => 1]],
            importsOut: [],
            importsIn: [['from' => 'src/Http/ProductController.php', 'to' => 'src/Models/Product.php']],
            internalImports: [],
            unlistedImportCount: 0,
            excerpts: [],
            omittedFiles: [],
            omittedFileCount: 0,
            characterBudget: 60000,
        );
    }

    /**
     * The context of a project without README, manifests, tests or license, unless they are given.
     *
     * @param  list<array{command: string, purpose: string, source: string, detail: string|null}>  $commands
     * @param  list<array{name: string, version: string|null, source: string}>  $requirements
     * @param  array{frameworks: list<array{name: string, source: string}>, test_files: int}|null  $tests
     * @param  array{name: string, source: string}|null  $license
     * @param  array{path: string, description: string|null, features: list<string>, problem: string|null, setup_commands: list<string>, excerpt: string, truncated: bool}|null  $readme
     * @param  list<array{path: string, kind: string}>  $entryPoints
     */
    private function overviewContext(array $commands = [], array $requirements = [], ?array $tests = null, ?array $license = null, ?array $readme = null, array $entryPoints = [], ?string $diagramsPage = null): OverviewContext
    {
        return new OverviewContext(
            repository: 'acme/magazin',
            name: 'magazin',
            readme: $readme,
            fileCount: 3,
            lineCount: 15,
            languages: [['name' => 'PHP', 'files' => 2, 'lines' => 14], ['name' => ContextBuilder::OTHER_LANGUAGE, 'files' => 1, 'lines' => 1]],
            manifests: [],
            topFolders: [['path' => 'src', 'files' => 2, 'lines' => 14, 'languages' => ['PHP']], ['path' => 'storage', 'files' => 1, 'lines' => 1, 'languages' => []]],
            modules: [
                ['path' => 'src/Models', 'files' => 2, 'lines' => 14, 'page' => '/repositories/1/docs/src-models', 'reason' => null],
                ['path' => 'storage', 'files' => 1, 'lines' => 1, 'page' => null, 'reason' => 'no code in a recognized language'],
            ],
            dependencies: [],
            dependencyCount: 0,
            entryPoints: $entryPoints,
            commands: $commands,
            requirements: $requirements,
            tests: $tests,
            license: $license,
            diagramsPage: $diagramsPage,
        );
    }
}
