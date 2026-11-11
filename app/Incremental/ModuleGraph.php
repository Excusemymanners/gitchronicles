<?php

namespace App\Incremental;

use App\Models\Repository;
use Illuminate\Support\Facades\DB;

/**
 * A picture of the code analysis at one moment: which module every file belongs to and which
 * module imports from which. The incremental update takes one picture before the new analysis
 * and one after it, and ImpactAnalysis compares them.
 *
 * Everything is kept by path, never by database id: the two pictures must be comparable even
 * though modules and files may have been added or deleted in between.
 */
final readonly class ModuleGraph
{
    /**
     * @param  array<string, string>  $moduleOfFile  file path => module path
     * @param  list<array{string, string}>  $edges  [the module that imports, the module it imports from]
     * @param  list<string>  $modulePaths  every module, in path order
     */
    public function __construct(
        public array $moduleOfFile = [],
        public array $edges = [],
        public array $modulePaths = [],
    ) {}

    /**
     * The picture of the repository's analysis as it is saved right now.
     */
    public static function of(Repository $repository): self
    {
        $modulePaths = $repository->modules()->orderBy('path')->pluck('path', 'id');

        $moduleOfFile = DB::table('repository_files')
            ->where('repository_id', $repository->id)
            ->whereNotNull('module_id')
            ->pluck('module_id', 'path')
            ->map(fn (int $moduleId): string => $modulePaths[$moduleId])
            ->all();

        $edges = DB::table('module_dependencies')
            ->where('repository_id', $repository->id)
            ->orderBy('id')
            ->get(['from_module_id', 'to_module_id'])
            ->map(fn (object $dependency): array => [$modulePaths[$dependency->from_module_id], $modulePaths[$dependency->to_module_id]])
            ->all();

        return new self($moduleOfFile, $edges, $modulePaths->values()->all());
    }
}
