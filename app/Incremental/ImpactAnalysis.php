<?php

namespace App\Incremental;

use App\Git\ChangeSet;

/**
 * Which modules a set of changed files affects (Faza 9), found in two steps:
 *
 * 1. the changed modules: a module that has a file that was added, modified or deleted, or whose
 *    list of files changed in any other way (ModuleGrouper may move files when a folder grows);
 * 2. their neighbours in the dependency graph, one step away: the modules that import from a
 *    changed module, and the modules a changed module imports from.
 *
 * Why one step and not the whole chain: the documentation page of a module is written from the
 * module's own files and from its direct import links (what it imports, who imports it), nothing
 * further (see ContextBuilder). So a change can alter the page of a module at most one step away;
 * two steps away nothing the writer sees is different. Going through the whole chain would mark
 * almost every module of a project whose modules all use the same base module.
 *
 * Being affected only means the page is checked: it is written again only when its context really
 * changed (the input hash, see DocumentationGenerator).
 */
final class ImpactAnalysis
{
    /**
     * The module has changed files (or another list of files than before).
     */
    public const VIA_CHANGED = 'changed';

    /**
     * The module imports from a changed module.
     */
    public const VIA_IMPORTS = 'imports';

    /**
     * The module is imported by a changed module.
     */
    public const VIA_IMPORTED = 'imported';

    /**
     * Compare the analysis before and after the changes.
     *
     * "modules" lists the affected modules that exist after the changes, the changed ones first;
     * "files" is how many of the module's files changed (0 for a neighbour).
     *
     * @return array{modules: list<array{path: string, files: int, via: string}>, modules_added: list<string>, modules_removed: list<string>}
     */
    public static function of(ChangeSet $changes, ModuleGraph $before, ModuleGraph $after): array
    {
        $changedFiles = self::changedFilesByModule($changes, $before, $after);
        $existing = array_flip($after->modulePaths);

        $modules = [];

        foreach ($changedFiles as $path => $files) {
            if (isset($existing[$path])) {
                $modules[$path] = ['path' => $path, 'files' => $files, 'via' => self::VIA_CHANGED];
            }
        }

        // The neighbours are looked up in both graphs: an import that was removed changes the two
        // modules it used to link just as much as a new one does.
        foreach ([...$before->edges, ...$after->edges] as [$importer, $imported]) {
            if (isset($changedFiles[$imported]) && isset($existing[$importer])) {
                $modules[$importer] ??= ['path' => $importer, 'files' => 0, 'via' => self::VIA_IMPORTS];
            }

            if (isset($changedFiles[$importer]) && isset($existing[$imported])) {
                $modules[$imported] ??= ['path' => $imported, 'files' => 0, 'via' => self::VIA_IMPORTED];
            }
        }

        $rank = [self::VIA_CHANGED => 0, self::VIA_IMPORTS => 1, self::VIA_IMPORTED => 2];
        usort($modules, fn (array $a, array $b): int => [$rank[$a['via']], $a['path']] <=> [$rank[$b['via']], $b['path']]);

        return [
            'modules' => $modules,
            'modules_added' => array_values(array_diff($after->modulePaths, $before->modulePaths)),
            'modules_removed' => array_values(array_diff($before->modulePaths, $after->modulePaths)),
        ];
    }

    /**
     * How many files changed in each module (module path => files). A file counts for the module
     * it was in before and for the module it is in now; a modified file counts once.
     * Changed files outside the analysis (images, lock files, libraries) belong to no module.
     *
     * @return array<string, int>
     */
    private static function changedFilesByModule(ChangeSet $changes, ModuleGraph $before, ModuleGraph $after): array
    {
        $counts = [];

        foreach ($changes->modified as $path) {
            $module = $after->moduleOfFile[$path] ?? null;

            if ($module !== null && $module === ($before->moduleOfFile[$path] ?? null)) {
                $counts[$module] = ($counts[$module] ?? 0) + 1;
            }
        }

        // Every file whose module is not the same as before: added, deleted, or moved by ModuleGrouper.
        foreach ($before->moduleOfFile + $after->moduleOfFile as $path => $unused) {
            $moduleBefore = $before->moduleOfFile[$path] ?? null;
            $moduleAfter = $after->moduleOfFile[$path] ?? null;

            if ($moduleBefore === $moduleAfter) {
                continue;
            }

            foreach (array_filter([$moduleBefore, $moduleAfter]) as $module) {
                $counts[$module] = ($counts[$module] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
