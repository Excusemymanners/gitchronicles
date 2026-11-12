<?php

namespace App\Documentation;

use App\Models\Document;
use App\Models\Module;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which pages the documentation of a repository has, and in which order they are written:
 * the module pages first, then the overview (which links to them), then the README.
 *
 * A module gets its own page when:
 * 1. it has code: at least one file whose language the analysis recognized (a folder with only
 *    ".gitignore" files has nothing to explain);
 * 2. it is among the biggest config('gitchronicle.docs.max_module_pages') modules by lines of code.
 * Every other module is still listed on the overview, with the reason it has no page.
 *
 * Each module page has a slug made from the module's path ("app/Http" → "app-http", "/" → "radacina"),
 * unique in the repository and never "overview" or "readme".
 */
final readonly class DocumentationPlan
{
    /**
     * The slug of the root folder's module ("/").
     */
    public const ROOT_SLUG = 'radacina';

    /**
     * @param  array<string, Module>  $modulePages  slug => module, in the order of the module paths
     * @param  list<array{module: Module, reason: string}>  $modulesWithoutPage  in the order of the module paths
     */
    private function __construct(
        public Repository $repository,
        public array $modulePages,
        public array $modulesWithoutPage,
    ) {}

    /**
     * Plan the documentation of an analyzed repository.
     */
    public static function for(Repository $repository): self
    {
        $modules = $repository->modules()->orderBy('path')->get();

        $codeLines = RepositoryFile::whereBelongsTo($repository)
            ->whereNotNull('language')
            ->groupBy('module_id')
            ->selectRaw('module_id, sum(lines) as code_lines')
            ->pluck('code_lines', 'module_id');

        $maxPages = (int) config('gitchronicle.docs.max_module_pages');

        $documented = $modules
            ->filter(fn (Module $module): bool => $codeLines->has($module->id))
            ->sortBy([['line_count', 'desc'], ['path', 'asc']])
            ->take($maxPages)
            ->keyBy('id');

        $slugs = self::slugsFor($modules);
        $modulePages = [];
        $modulesWithoutPage = [];

        foreach ($modules as $module) {
            if ($documented->has($module->id)) {
                $modulePages[$slugs[$module->id]] = $module;
            } else {
                $modulesWithoutPage[] = [
                    'module' => $module,
                    'reason' => $codeLines->has($module->id)
                        ? self::overTheLimit($maxPages)
                        : 'no code in a recognized language',
                ];
            }
        }

        return new self($repository, $modulePages, $modulesWithoutPage);
    }

    /**
     * Every page to write, in order: the module pages, the overview, the README.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        return [...array_keys($this->modulePages), Document::OVERVIEW, Document::README];
    }

    /**
     * The module of a module page, or null for the overview, the README or an unknown slug.
     */
    public function moduleFor(string $slug): ?Module
    {
        return $this->modulePages[$slug] ?? null;
    }

    /**
     * The slug of a module's page, or null when the module has no page.
     */
    public function slugOf(Module $module): ?string
    {
        foreach ($this->modulePages as $slug => $documentedModule) {
            if ($documentedModule->is($module)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * The reason of a module left out by the page limit.
     */
    private static function overTheLimit(int $maxPages): string
    {
        return $maxPages === 1
            ? 'over the limit: only the biggest module has a page'
            : "over the limit: only the {$maxPages} biggest modules have a page";
    }

    /**
     * A unique slug for every module, computed over all modules in the order of their paths, so the slug
     * of a module does not change when other modules get or lose their page. On a clash, "-2", "-3", ...
     * is added; "overview" and "readme" are taken by the overview and the README.
     *
     * @param  Collection<int, Module>  $modules
     * @return array<int, string> module id => slug
     */
    private static function slugsFor(Collection $modules): array
    {
        $taken = [Document::OVERVIEW => true, Document::README => true];
        $slugs = [];

        foreach ($modules as $module) {
            $base = $module->path === '/'
                ? self::ROOT_SLUG
                : (Str::slug(str_replace(['/', '_', '.'], ' ', $module->path)) ?: 'modul');

            $slug = $base;

            for ($suffix = 2; isset($taken[$slug]); $suffix++) {
                $slug = "{$base}-{$suffix}";
            }

            $taken[$slug] = true;
            $slugs[$module->id] = $slug;
        }

        return $slugs;
    }
}
