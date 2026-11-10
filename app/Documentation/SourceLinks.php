<?php

namespace App\Documentation;

use App\Models\Repository;

/**
 * The links from the documentation to the code on GitHub, always to one exact commit (a permalink),
 * so a link keeps pointing to the same lines even after the code changes:
 *
 *     https://github.com/{owner}/{name}/blob/{sha}/{path}#L{start}-L{end}
 *
 * The commit is not part of the writer's input hash (ContextBuilder): otherwise every new commit
 * would rewrite every page. A page that is not rewritten keeps its links to the commit it was written
 * from (documents.source_sha), and those lines are still the same because the module did not change.
 */
final readonly class SourceLinks
{
    /**
     * Create the links of one repository at one commit.
     */
    public function __construct(
        public string $owner,
        public string $name,
        public string $sha,
    ) {}

    /**
     * The links to the last processed commit of the repository (its branch while it has no commit yet).
     */
    public static function for(Repository $repository): self
    {
        return new self($repository->owner, $repository->name, $repository->last_sha ?? $repository->default_branch ?? 'HEAD');
    }

    /**
     * The start of every link to a file, e.g. "https://github.com/laravel/framework/blob/abc123/".
     */
    public function fileBase(): string
    {
        return "https://github.com/{$this->owner}/{$this->name}/blob/{$this->sha}/";
    }

    /**
     * A link to some lines of a file, e.g. ".../blob/abc123/app/Models/User.php#L10-L42".
     */
    public function lines(string $path, int $startLine, int $endLine): string
    {
        return $this->file($path)."#L{$startLine}-L{$endLine}";
    }

    /**
     * A link to a whole file.
     */
    public function file(string $path): string
    {
        return $this->fileBase().self::encodePath($path);
    }

    /**
     * A link to a folder ("/" is the whole repository).
     */
    public function folder(string $path): string
    {
        $base = "https://github.com/{$this->owner}/{$this->name}/tree/{$this->sha}";

        return $path === '/' ? $base : $base.'/'.self::encodePath($path);
    }

    /**
     * The URL for `git clone`.
     */
    public function cloneUrl(): string
    {
        return "https://github.com/{$this->owner}/{$this->name}.git";
    }

    /**
     * A path made safe for a URL, one folder at a time: "plan tematic.doc" → "plan%20tematic.doc".
     */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
