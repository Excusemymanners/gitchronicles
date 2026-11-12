<?php

namespace App\Consulting\Runtime;

/**
 * One runtime or framework version a repository declares, and where: "PHP ^8.2 in composer.json line 9".
 *
 * `version` is what the check compares: the version itself when it is pinned, the lowest version a range
 * allows otherwise (`isRange`). A framework version read from a lock file is the one really installed.
 */
final readonly class Declaration
{
    /**
     * @param  string  $product  key of Products::all(), e.g. "php", "nodejs", "laravel"
     * @param  string  $declared  the text as written in the file ("^8.2", "node:20-alpine" gives "20")
     */
    public function __construct(
        public string $product,
        public string $declared,
        public string $version,
        public bool $isRange,
        public string $path,
        public ?int $line,
    ) {}
}
