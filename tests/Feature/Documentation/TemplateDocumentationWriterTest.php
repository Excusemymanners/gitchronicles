<?php

namespace Tests\Feature\Documentation;

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
            ['## Rol', '## Componente principale', '## Cum funcționează', '## Dependențe', '## Fișiere'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringStartsWith('# Modulul `src/Models`', $page->markdown);
        $this->assertStringContainsString(
            '**[`Product`](https://github.com/acme/magazin/blob/abc123/src/Models/Product.php#L3-L9)** — clasă în `Product.php`, liniile 3–9. Conține: `price`.',
            $page->markdown,
        );
        $this->assertStringContainsString('[`Plan tematic.php`](https://github.com/acme/magazin/blob/abc123/src/Models/Plan%20tematic.php)', $page->markdown);
        $this->assertStringContainsString('- modulul `src/Http` (1 import)', $page->markdown);
    }

    public function test_module_page_keeps_names_with_markdown_characters_as_code(): void
    {
        $context = $this->moduleContext(symbols: [
            ['path' => 'src/Models/Product.php', 'kind' => 'method', 'name' => '__init__', 'start_line' => 1, 'end_line' => 2],
        ]);

        $page = (new TemplateDocumentationWriter)->module($context, $this->links());

        $this->assertStringContainsString('**[`__init__`](', $page->markdown);
    }

    public function test_overview_says_so_when_the_repository_shows_no_way_to_run_it(): void
    {
        $page = (new TemplateDocumentationWriter)->overview($this->overviewContext(), $this->links());

        $this->assertSame(
            ['## Ce face proiectul', '## Tehnologii', '## Arhitectură', '## Module', '## Cum îl rulezi'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringContainsString('Repository-ul nu are un README în folderul principal', $page->markdown);
        $this->assertStringContainsString('Fișierele proiectului nu arată cum se instalează sau se pornește', $page->markdown);
        $this->assertStringContainsString('| [`src/Models`](/repositories/1/docs/src-models) | 2 | 14 | pagină proprie |', $page->markdown);
        $this->assertStringContainsString('| `storage` | 1 | 1 | fără pagină: nu conține cod într-un limbaj recunoscut |', $page->markdown);
    }

    public function test_readme_leaves_out_every_section_without_evidence(): void
    {
        $page = (new TemplateDocumentationWriter)->readme($this->overviewContext(), $this->links());

        $this->assertSame(
            ['## Tehnologii', '## Structura proiectului', '## Module'],
            array_values(preg_grep('/\A## /', explode("\n", $page->markdown))),
        );
        $this->assertStringStartsWith("# magazin\n\nRepository-ul `acme/magazin` are 3 fișiere și 15 linii, scrise mai ales în PHP.", $page->markdown);
        $this->assertStringNotContainsString('docs/src-models', $page->markdown);
        $this->assertStringContainsString('[`src/Models`](https://github.com/acme/magazin/tree/abc123/src/Models)', $page->markdown);
    }

    public function test_readme_of_a_project_without_code_or_manifests_has_no_technologies_section(): void
    {
        $context = new OverviewContext(
            repository: 'octocat/hello-world', name: 'hello-world', readme: null, fileCount: 1, lineCount: 1,
            languages: [['name' => 'Altele', 'files' => 1, 'lines' => 1]], manifests: [],
            topFolders: [['path' => '/', 'files' => 1, 'lines' => 1, 'languages' => []]],
            modules: [['path' => '/', 'files' => 1, 'lines' => 1, 'page' => null, 'reason' => 'nu conține cod într-un limbaj recunoscut']],
            dependencies: [], dependencyCount: 0, entryPoints: [], commands: [], requirements: [], tests: null, license: null,
        );

        $page = (new TemplateDocumentationWriter)->readme($context, $this->links());

        $this->assertSame(['## Structura proiectului', '## Module'], array_values(preg_grep('/\A## /', explode("\n", $page->markdown))));
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

        $this->assertStringContainsString("## Instalare\n\n```bash\ngit clone https://github.com/acme/magazin.git\ncd magazin\ncomposer install\n```", $page->markdown);
        $this->assertStringContainsString("## Rulare\n\n```bash\ncomposer run dev\n```", $page->markdown);
        $this->assertStringContainsString("- PHPUnit (din `phpunit.xml`)\n- 4 fișiere de test\n\n```bash\ncomposer run test\n```", $page->markdown);
        $this->assertStringContainsString("## Licență\n\nMIT (din `LICENSE`).", $page->markdown);
        $this->assertStringContainsString('- PHP `^8.3` (din `composer.json`)', $page->markdown);
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
     */
    private function overviewContext(array $commands = [], array $requirements = [], ?array $tests = null, ?array $license = null): OverviewContext
    {
        return new OverviewContext(
            repository: 'acme/magazin',
            name: 'magazin',
            readme: null,
            fileCount: 3,
            lineCount: 15,
            languages: [['name' => 'PHP', 'files' => 2, 'lines' => 14], ['name' => 'Altele', 'files' => 1, 'lines' => 1]],
            manifests: [],
            topFolders: [['path' => 'src', 'files' => 2, 'lines' => 14, 'languages' => ['PHP']], ['path' => 'storage', 'files' => 1, 'lines' => 1, 'languages' => []]],
            modules: [
                ['path' => 'src/Models', 'files' => 2, 'lines' => 14, 'page' => '/repositories/1/docs/src-models', 'reason' => null],
                ['path' => 'storage', 'files' => 1, 'lines' => 1, 'page' => null, 'reason' => 'nu conține cod într-un limbaj recunoscut'],
            ],
            dependencies: [],
            dependencyCount: 0,
            entryPoints: [],
            commands: $commands,
            requirements: $requirements,
            tests: $tests,
            license: $license,
        );
    }
}
