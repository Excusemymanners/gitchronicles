<?php

namespace Tests\Concerns;

use App\Models\CodeSymbol;
use App\Models\FileImport;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a repository as the code analysis (Faza 5) would leave it, without Python: the files are written
 * into its clone folder (on the fake "local" disk of TestCase) and saved with their modules, symbols and imports.
 */
trait CreatesAnalyzedRepositories
{
    /**
     * A cloned and analyzed repository "acme/magazin" at the commit aaaa…a.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function analyzedRepository(array $attributes = []): Repository
    {
        return Repository::factory()->ready()->analyzed()->create([
            'owner' => 'acme',
            'name' => 'magazin',
            'url' => 'https://github.com/acme/magazin',
            'last_sha' => str_repeat('a', 40),
            ...$attributes,
        ]);
    }

    /**
     * An empty module (folder) of the repository; addFile() fills it.
     */
    protected function addModule(Repository $repository, string $path): Module
    {
        return Module::factory()->for($repository)->create([
            'path' => $path,
            'name' => $path === '/' ? $repository->name : $path,
            'file_count' => 0,
            'line_count' => 0,
        ]);
    }

    /**
     * Write a file into the clone and save it in the module, which counts one more file and its lines.
     */
    protected function addFile(Module $module, string $path, string $content, ?string $language = 'PHP'): RepositoryFile
    {
        Storage::disk('local')->put("repositories/{$module->repository_id}/{$path}", $content);

        $lines = $content === '' ? 0 : substr_count(rtrim($content, "\n"), "\n") + 1;

        $file = RepositoryFile::factory()->for($module->repository)->for($module)->create([
            'path' => $path,
            'language' => $language,
            'lines' => $lines,
            'size_bytes' => strlen($content),
        ]);

        $module->increment('file_count');
        $module->increment('line_count', $lines);

        return $file;
    }

    /**
     * A class, function, ... found in a file.
     */
    protected function addSymbol(RepositoryFile $file, string $kind, string $name, int $startLine, int $endLine): CodeSymbol
    {
        return CodeSymbol::factory()->create([
            'repository_id' => $file->repository_id,
            'repository_file_id' => $file->id,
            'kind' => $kind,
            'name' => $name,
            'start_line' => $startLine,
            'end_line' => $endLine,
        ]);
    }

    /**
     * An import of a file: of another file of the repository, or of a library (a string).
     */
    protected function addImport(RepositoryFile $from, RepositoryFile|string $to): FileImport
    {
        return FileImport::factory()->create([
            'repository_id' => $from->repository_id,
            'repository_file_id' => $from->id,
            'target' => $to instanceof RepositoryFile ? $to->path : $to,
            'target_file_id' => $to instanceof RepositoryFile ? $to->id : null,
            'is_external' => ! $to instanceof RepositoryFile,
        ]);
    }

    /**
     * A dependency between two modules, with its number of imports.
     */
    protected function addDependency(Module $from, Module $to, int $weight): ModuleDependency
    {
        return ModuleDependency::factory()->create([
            'repository_id' => $from->repository_id,
            'from_module_id' => $from->id,
            'to_module_id' => $to->id,
            'weight' => $weight,
        ]);
    }

    /**
     * A small shop: the module "src/Models" (Product, Category) used by "src/Http" (ProductController),
     * plus a README and a composer.json in the root folder.
     *
     * @return array{repository: Repository, models: Module, http: Module, root: Module}
     */
    protected function shopRepository(): array
    {
        $repository = $this->analyzedRepository();

        $root = $this->addModule($repository, '/');
        $this->addFile($root, 'README.md', "# Magazin\n\nUn magazin mic, folosit ca exemplu.\n", 'Markdown');
        $this->addFile($root, 'composer.json', json_encode([
            'name' => 'acme/magazin',
            'license' => 'MIT',
            'require' => ['php' => '^8.3'],
            'scripts' => ['test' => 'phpunit'],
        ], JSON_PRETTY_PRINT)."\n", 'JSON');

        $models = $this->addModule($repository, 'src/Models');
        $product = $this->addFile($models, 'src/Models/Product.php', "<?php\n\nclass Product\n{\n    public function price(): int\n    {\n        return 10;\n    }\n}\n");
        $category = $this->addFile($models, 'src/Models/Category.php', "<?php\n\nclass Category\n{\n}\n");
        $this->addSymbol($product, 'class', 'Product', 3, 9);
        $this->addSymbol($product, 'method', 'price', 5, 8);
        $this->addSymbol($category, 'class', 'Category', 3, 5);
        $this->addImport($category, $product);

        $http = $this->addModule($repository, 'src/Http');
        $controller = $this->addFile($http, 'src/Http/ProductController.php', "<?php\n\nclass ProductController\n{\n    public function show(): void\n    {\n    }\n}\n");
        $this->addSymbol($controller, 'class', 'ProductController', 3, 8);
        $this->addSymbol($controller, 'method', 'show', 5, 7);
        $this->addImport($controller, $product);
        $this->addImport($controller, 'Illuminate\Http\Request');
        $this->addDependency($http, $models, 1);

        return ['repository' => $repository, 'models' => $models, 'http' => $http, 'root' => $root];
    }
}
