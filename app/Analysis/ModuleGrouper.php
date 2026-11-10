<?php

namespace App\Analysis;

/**
 * Groups the files of a repository into modules: a module is a folder.
 *
 * 1. A file belongs to the folder it is in, cut to the first two levels:
 *    "app/Http/Controllers/Auth/LoginController.php" → "app/Http", "routes/web.php" → "routes".
 *    The files of the root folder ("README.md") form the module "/".
 * 2. A module with more than 40 files is too big for one documentation page, so it is split
 *    one level deeper, only once: "app/Http" → "app/Http/Controllers", "app/Http/Middleware", ...
 *    Its files that sit directly in "app/Http" stay in "app/Http".
 */
final class ModuleGrouper
{
    /**
     * The module of the files in the root folder of the repository.
     */
    public const ROOT_MODULE = '/';

    /**
     * How many folder levels a module path keeps.
     */
    public const DEPTH = 2;

    /**
     * A module with more files than this is split one level deeper.
     */
    public const MAX_FILES = 40;

    /**
     * The module of every file.
     *
     * @param  list<string>  $paths  file paths relative to the repository, like "app/Models/User.php"
     * @return array<string, string> file path => module path
     */
    public static function group(array $paths): array
    {
        $modules = [];

        foreach ($paths as $path) {
            $modules[$path] = self::folderAtDepth($path, self::DEPTH);
        }

        $fileCounts = array_count_values($modules);

        foreach ($modules as $path => $module) {
            if ($fileCounts[$module] > self::MAX_FILES) {
                $modules[$path] = self::folderAtDepth((string) $path, self::DEPTH + 1);
            }
        }

        return $modules;
    }

    /**
     * The folder of a file, cut to the given number of levels ("/" for the root folder).
     */
    private static function folderAtDepth(string $path, int $depth): string
    {
        $folders = array_slice(explode('/', $path), 0, -1);

        if ($folders === []) {
            return self::ROOT_MODULE;
        }

        return implode('/', array_slice($folders, 0, $depth));
    }
}
