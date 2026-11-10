<?php

namespace App\Http\Resources;

use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the repository page shows about the code analysis, in the „Structura codului” section
 * (the CodeStructure type in TypeScript): the key numbers, the languages, the modules and
 * the strongest dependencies between modules. Make it only for an analyzed repository.
 *
 * @mixin Repository
 */
class CodeStructureResource extends JsonResource
{
    /**
     * How many dependencies the page lists: the strongest ones (the biggest weight).
     */
    public const SHOWN_DEPENDENCIES = 10;

    /**
     * Transform the resource into an array. It runs four small queries: languages, modules,
     * the strongest dependencies and their total number.
     *
     * Files without a known language are counted under „Altele”.
     *
     * @return array{file_count: int, line_count: int, module_count: int, language_count: int, languages: list<array{name: string, file_count: int, line_count: int}>, modules: list<array{id: int, path: string, name: string, file_count: int, line_count: int}>, dependencies: list<array{from: string, to: string, weight: int}>, dependency_count: int}
     */
    public function toArray(Request $request): array
    {
        $languages = $this->files()
            ->toBase()
            ->selectRaw('language, count(*) as file_count, sum(lines) as line_count')
            ->groupBy('language')
            ->orderByDesc('line_count')
            ->orderBy('language')
            ->get();

        $modules = $this->modules()
            ->orderBy('path')
            ->get(['id', 'path', 'name', 'file_count', 'line_count']);

        $strongestDependencies = $this->moduleDependencies()
            ->with(['fromModule:id,path', 'toModule:id,path'])
            ->orderByDesc('weight')
            ->orderBy('id')
            ->limit(self::SHOWN_DEPENDENCIES)
            ->get();

        return [
            'file_count' => (int) $languages->sum('file_count'),
            'line_count' => (int) $languages->sum('line_count'),
            'module_count' => $modules->count(),
            'language_count' => $languages->whereNotNull('language')->count(),
            'languages' => $languages->map(fn (object $language): array => [
                'name' => $language->language ?? 'Altele',
                'file_count' => (int) $language->file_count,
                'line_count' => (int) $language->line_count,
            ])->all(),
            'modules' => $modules->map(fn (Module $module): array => [
                'id' => $module->id,
                'path' => $module->path,
                'name' => $module->name,
                'file_count' => $module->file_count,
                'line_count' => $module->line_count,
            ])->all(),
            'dependencies' => $strongestDependencies->map(fn (ModuleDependency $dependency): array => [
                'from' => $dependency->fromModule->path,
                'to' => $dependency->toModule->path,
                'weight' => $dependency->weight,
            ])->all(),
            'dependency_count' => $this->moduleDependencies()->count(),
        ];
    }
}
