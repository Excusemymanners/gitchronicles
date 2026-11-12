<?php

namespace Tests\Feature\Documentation;

use App\Documentation\ContextBuilder;
use App\Documentation\DocumentationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAnalyzedRepositories;
use Tests\TestCase;

/**
 * What the documentation writers receive: deterministic, within the character budget, with every file
 * that was left out listed, and only real facts read from the analysis and the clone.
 */
class ContextBuilderTest extends TestCase
{
    use CreatesAnalyzedRepositories;
    use RefreshDatabase;

    public function test_builds_the_same_module_context_and_input_hash_every_time(): void
    {
        ['models' => $models] = $this->shopRepository();

        $first = app(ContextBuilder::class)->module($models);
        $second = app(ContextBuilder::class)->module($models->fresh());

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame($first->inputHash(), $second->inputHash());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first->inputHash());
    }

    public function test_module_context_holds_the_files_symbols_imports_and_numbered_source(): void
    {
        ['models' => $models] = $this->shopRepository();

        $context = app(ContextBuilder::class)->module($models);

        $this->assertSame('acme/magazin', $context->repository);
        $this->assertSame([
            ['path' => 'src/Models/Category.php', 'language' => 'PHP', 'lines' => 5],
            ['path' => 'src/Models/Product.php', 'language' => 'PHP', 'lines' => 9],
        ], $context->files);
        $this->assertSame([
            ['path' => 'src/Models/Product.php', 'kind' => 'class', 'name' => 'Product', 'start_line' => 3, 'end_line' => 9],
            ['path' => 'src/Models/Product.php', 'kind' => 'method', 'name' => 'price', 'start_line' => 5, 'end_line' => 8],
            ['path' => 'src/Models/Category.php', 'kind' => 'class', 'name' => 'Category', 'start_line' => 3, 'end_line' => 5],
        ], $context->symbols);
        $this->assertSame([['path' => 'src/Http', 'weight' => 1]], $context->usedByModules);
        $this->assertSame([['from' => 'src/Http/ProductController.php', 'to' => 'src/Models/Product.php']], $context->importsIn);
        $this->assertSame([['from' => 'src/Models/Category.php', 'to' => 'src/Models/Product.php']], $context->internalImports);
        $this->assertSame('src/Models/Product.php', $context->excerpts[0]['path']);
        $this->assertStringStartsWith("1| <?php\n2| \n3| class Product", $context->excerpts[0]['content']);
        $this->assertSame([], $context->omittedFiles);
    }

    public function test_sends_the_most_imported_file_first_and_cuts_the_first_file_that_does_not_fit(): void
    {
        config(['gitchronicle.docs.module_context_characters' => 2000]);
        $repository = $this->analyzedRepository();
        $module = $this->addModule($repository, 'src');
        $big = $this->addFile($module, 'src/Big.php', str_repeat("// o linie lungă de comentariu, ca să umple bugetul\n", 60));
        $used = $this->addFile($module, 'src/Used.php', "<?php\n\nfunction used(): void {}\n");
        $small = $this->addFile($module, 'src/Small.php', "<?php\n");
        $this->addImport($big, $used);
        $this->addImport($small, $used);

        $context = app(ContextBuilder::class)->module($module);

        $this->assertSame(['src/Used.php', 'src/Big.php'], array_column($context->excerpts, 'path'));
        $this->assertSame(3, $context->excerpts[0]['end_line']);
        $this->assertSame(60, $context->excerpts[1]['total_lines']);
        $this->assertLessThan(60, $context->excerpts[1]['end_line']);
        $this->assertLessThanOrEqual(2000, mb_strlen(implode('', array_column($context->excerpts, 'content'))));
        $this->assertSame([['path' => 'src/Small.php', 'lines' => 1, 'reason' => 'did not fit within the character budget']], $context->omittedFiles);
        $this->assertSame(1, $context->omittedFileCount);
    }

    public function test_omits_a_file_whose_beginning_would_be_too_short_to_be_useful(): void
    {
        config(['gitchronicle.docs.module_context_characters' => 1000]);
        $repository = $this->analyzedRepository();
        $module = $this->addModule($repository, 'src');
        $this->addFile($module, 'src/Big.php', str_repeat("// o linie lungă de comentariu, ca să umple bugetul\n", 60));

        $context = app(ContextBuilder::class)->module($module);

        $this->assertSame([], $context->excerpts);
        $this->assertSame([['path' => 'src/Big.php', 'lines' => 60, 'reason' => 'did not fit within the character budget']], $context->omittedFiles);
    }

    public function test_never_reads_a_symbolic_link_of_the_clone(): void
    {
        $repository = $this->analyzedRepository();
        $module = $this->addModule($repository, 'src');
        $link = $this->addFile($module, 'src/secret.txt', 'înlocuit cu o legătură', null);
        Storage::disk('local')->delete('repositories/'.$repository->id.'/src/secret.txt');
        symlink('/etc/hostname', Storage::disk('local')->path("repositories/{$repository->id}/src/secret.txt"));

        $context = app(ContextBuilder::class)->module($module);

        $this->assertSame([], $context->excerpts);
        $this->assertSame([['path' => $link->path, 'lines' => 1, 'reason' => 'not readable as text']], $context->omittedFiles);
    }

    public function test_input_hash_changes_with_the_code_but_not_with_the_commit(): void
    {
        ['repository' => $repository, 'models' => $models] = $this->shopRepository();
        $before = app(ContextBuilder::class)->module($models)->inputHash();

        $repository->update(['last_sha' => str_repeat('b', 40)]);
        $afterNewCommit = app(ContextBuilder::class)->module($models->fresh())->inputHash();
        Storage::disk('local')->put("repositories/{$repository->id}/src/Models/Category.php", "<?php\n\nclass Category extends Base\n{\n}\n");
        $afterCodeChange = app(ContextBuilder::class)->module($models->fresh())->inputHash();

        $this->assertSame($before, $afterNewCommit);
        $this->assertNotSame($before, $afterCodeChange);
    }

    public function test_overview_context_reads_the_readme_manifests_license_and_commands_of_the_root_folder(): void
    {
        ['repository' => $repository, 'root' => $root] = $this->shopRepository();
        Storage::disk('local')->put("repositories/{$repository->id}/README.md", "# Magazin\n\nUn magazin mic.\n\n## Why\n\nCoșurile se pierd la sfârșitul sesiunii.\n\n## Instalare\n\n```bash\n# nu e despre setup\n```\n\n## Installation\n\n```bash\ncp .env.example .env\n```\n\n## Funcționalități\n\n- Coșul de cumpărături\n- Plata cu cardul\n\n## Altceva\n\n- nu e o funcționalitate\n");
        $this->addFile($root, 'LICENSE', "MIT License\n\nCopyright (c) 2026 Acme\n", null);
        $this->addFile($root, 'package.json', json_encode(['scripts' => ['dev' => 'vite', 'postinstall' => 'echo gata'], 'devDependencies' => ['vitest' => '^3.0']])."\n", 'JSON');
        $this->addFile($root, 'artisan', "#!/usr/bin/env php\n", null);
        Storage::disk('local')->put("repositories/{$repository->id}/pnpm-lock.yaml", "lockfileVersion: '9.0'\n");

        $context = app(ContextBuilder::class)->overview(DocumentationPlan::for($repository));

        $this->assertSame('Un magazin mic.', $context->readme['description']);
        $this->assertSame(['Coșul de cumpărături', 'Plata cu cardul'], $context->readme['features']);
        $this->assertSame('Coșurile se pierd la sfârșitul sesiunii.', $context->readme['problem']);
        $this->assertSame(['cp .env.example .env'], $context->readme['setup_commands']);
        $this->assertSame("/repositories/{$repository->id}/diagrams", $context->diagramsPage);
        $this->assertSame(['name' => 'MIT', 'source' => 'LICENSE'], $context->license);
        $this->assertSame([
            ['command' => 'composer install', 'purpose' => 'install', 'source' => 'composer.json', 'detail' => null],
            ['command' => 'composer run test', 'purpose' => 'test', 'source' => 'composer.json', 'detail' => 'phpunit'],
            ['command' => 'pnpm install', 'purpose' => 'install', 'source' => 'pnpm-lock.yaml', 'detail' => null],
            ['command' => 'pnpm run dev', 'purpose' => 'run', 'source' => 'package.json', 'detail' => 'vite'],
        ], $context->commands);
        $this->assertSame([
            ['name' => 'PHP', 'version' => '^8.3', 'source' => 'composer.json'],
            ['name' => 'Composer', 'version' => null, 'source' => 'composer.json'],
            ['name' => 'Node.js', 'version' => null, 'source' => 'package.json'],
            ['name' => 'pnpm', 'version' => null, 'source' => 'pnpm-lock.yaml'],
        ], $context->requirements);
        $this->assertSame([['name' => 'Vitest', 'source' => 'package.json']], $context->tests['frameworks']);
        $this->assertSame([['path' => 'artisan', 'kind' => 'Laravel command line (Artisan)']], $context->entryPoints);
    }

    public function test_overview_context_lists_every_module_with_its_page_or_the_reason_it_has_none(): void
    {
        config(['gitchronicle.docs.max_module_pages' => 2]);
        ['repository' => $repository] = $this->shopRepository();
        $storage = $this->addModule($repository, 'storage');
        $this->addFile($storage, 'storage/.gitignore', "*\n", null);

        $context = app(ContextBuilder::class)->overview(DocumentationPlan::for($repository));

        $this->assertSame([
            ['path' => '/', 'files' => 2, 'lines' => 13, 'page' => "/repositories/{$repository->id}/docs/radacina", 'reason' => null],
            ['path' => 'src/Http', 'files' => 1, 'lines' => 8, 'page' => null, 'reason' => 'over the limit: only the 2 biggest modules have a page'],
            ['path' => 'src/Models', 'files' => 2, 'lines' => 14, 'page' => "/repositories/{$repository->id}/docs/src-models", 'reason' => null],
            ['path' => 'storage', 'files' => 1, 'lines' => 1, 'page' => null, 'reason' => 'no code in a recognized language'],
        ], $context->modules);
    }
}
