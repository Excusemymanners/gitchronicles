<?php

namespace App\Documentation;

use App\Models\Repository;

/**
 * Reads text files from a repository's clone, safely. The clone is code from the internet, so a path
 * is read only when it is a normal file inside the clone: never a symbolic link (it could point to
 * /etc/passwd or to the app's .env), never a path that leads out of the clone ("../"), never a file
 * that is not UTF-8 text.
 */
final class CloneFiles
{
    /**
     * The real path of the clone folder, or null when it does not exist.
     */
    private ?string $root;

    /**
     * The most bytes read from one file (a README or a manifest is never this big; a hostile file may be).
     */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Prepare reading the clone of the repository.
     */
    public function __construct(Repository $repository)
    {
        $root = realpath($repository->clonePath());

        $this->root = $root === false ? null : $root;
    }

    /**
     * The content of a file of the clone ("composer.json", "app/Models/User.php"), or null when it
     * is missing, is a symbolic link, is outside the clone or is not UTF-8 text.
     */
    public function read(string $path): ?string
    {
        $fullPath = $this->safePath($path);

        if ($fullPath === null) {
            return null;
        }

        // A tracked file may be as big as the clone is allowed to be: only the first MAX_BYTES are read.
        $content = file_get_contents($fullPath, false, null, 0, self::MAX_BYTES + 4);

        if ($content === false) {
            return null;
        }

        if (strlen($content) > self::MAX_BYTES) {
            $content = mb_strcut($content, 0, self::MAX_BYTES, 'UTF-8');
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : null;
    }

    /**
     * Whether the clone has this file (a normal file, not a link).
     */
    public function exists(string $path): bool
    {
        return $this->safePath($path) !== null;
    }

    /**
     * The absolute path of a normal file inside the clone, or null.
     */
    private function safePath(string $path): ?string
    {
        if ($this->root === null || $path === '' || str_contains($path, "\0")) {
            return null;
        }

        $fullPath = $this->root.'/'.$path;

        if (is_link($fullPath) || ! is_file($fullPath)) {
            return null;
        }

        $realPath = realpath($fullPath);

        return $realPath !== false && str_starts_with($realPath, $this->root.'/') ? $realPath : null;
    }
}
