<?php

namespace App\Analysis;

/**
 * The files of a repository that a string in the code can point to, found by name instead of an import:
 * - an Inertia page ("repositories/show" → resources/js/pages/repositories/show.tsx),
 * - a Blade view ("emails.welcome" → resources/views/emails/welcome.blade.php),
 * - a script started by another program ("analyzer/analyze.py", "/slides/diagram.mjs"),
 * - a package of the same repository imported by its name ("@acme/core" → packages/core/src/index.ts).
 */
final class LinkTargets
{
    /**
     * Extensions of the scripts another program can start.
     */
    private const SCRIPT_EXTENSIONS = ['py', 'js', 'mjs', 'cjs', 'ts', 'sh', 'bash', 'rb', 'pl'];

    /**
     * Page key => path.
     *
     * @var array<string, string>
     */
    private array $pages = [];

    /**
     * Blade view key => path.
     *
     * @var array<string, string>
     */
    private array $views = [];

    /**
     * Script path => true, and the suffixes of script paths ("slides/diagram.mjs") => path, or false when
     * two scripts end the same way.
     *
     * @var array<string, string|false>
     */
    private array $scripts = [];

    /**
     * Workspace package name => [folder, entry file or null].
     *
     * @var array<string, array{string, string|null}>
     */
    private array $packages = [];

    /**
     * Every analyzed path => true.
     *
     * @var array<string, true>
     */
    private array $paths;

    /**
     * Build the lookups from the analyzed paths and the package.json files of the repository.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $packageJsons  path of a package.json => its contents
     */
    public function __construct(array $paths, array $packageJsons = [])
    {
        $this->paths = array_fill_keys($paths, true);

        foreach ($paths as $path) {
            if (preg_match('#(?:^|/)(?:pages|Pages)/(.+)\.(?:tsx|jsx|vue|svelte|ts|js)$#', $path, $match) === 1) {
                $this->pages[$match[1]] ??= $path;
            }

            if (preg_match('#(?:^|/)resources/views/(.+)\.blade\.php$#', $path, $match) === 1) {
                $this->views[str_replace('/', '.', $match[1])] = $path;
            }

            if (in_array(pathinfo($path, PATHINFO_EXTENSION), self::SCRIPT_EXTENSIONS, true) && str_contains($path, '/')) {
                $this->scripts[$path] = $path;
                $parts = explode('/', $path);

                for ($i = 1; $i < count($parts) - 1; $i++) {
                    $suffix = implode('/', array_slice($parts, $i));
                    $this->scripts[$suffix] = isset($this->scripts[$suffix]) && $this->scripts[$suffix] !== $path ? false : $path;
                }
            }
        }

        foreach ($packageJsons as $manifest => $contents) {
            $package = json_decode($contents, true);

            if (is_array($package) && is_string($package['name'] ?? null) && $package['name'] !== '') {
                $folder = dirname($manifest) === '.' ? '' : dirname($manifest);
                $this->packages[$package['name']] = [$folder, $this->packageEntry($folder, $package)];
            }
        }
    }

    /**
     * The page file a page name points to, or null.
     */
    public function page(string $name): ?string
    {
        return preg_match('#\A[\w\-]+(/[\w\-\[\].]+)*\z#', $name) === 1 ? ($this->pages[$name] ?? null) : null;
    }

    /**
     * The Blade view file a view name points to, or null.
     */
    public function view(string $name): ?string
    {
        return $this->views[$name] ?? null;
    }

    /**
     * The script a path string points to ("analyzer/analyze.py", "./scripts/build.sh", "/slides/diagram.mjs"),
     * when exactly one script of the repository has that path or ends with it (at least one folder), or null.
     */
    public function script(string $text): ?string
    {
        $path = preg_replace('#\A(\./|/)+#', '', trim($text)) ?? '';

        if (! str_contains($path, '/') || str_contains($path, ' ') || str_contains($path, '..')) {
            return null;
        }

        $script = $this->scripts[$path] ?? null;

        return is_string($script) ? $script : null;
    }

    /**
     * The file an import of a workspace package points to ("@acme/core" → its entry file,
     * "@acme/core/utils/format" → that file of the package), or null.
     */
    public function package(string $import): ?string
    {
        foreach ($this->packages as $name => [$folder, $entry]) {
            if ($import === $name) {
                return $entry;
            }

            if (str_starts_with($import, "{$name}/")) {
                $rest = substr($import, strlen($name) + 1);

                return $this->existing([$this->join($folder, $rest), $this->join($folder, "src/{$rest}")]);
            }
        }

        return null;
    }

    /**
     * Whether the repository has workspace packages to resolve.
     */
    public function hasPackages(): bool
    {
        return $this->packages !== [];
    }

    /**
     * Every analyzed file under a folder with one of the extensions (for `import.meta.glob('./pages/**')`).
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public function filesUnder(string $folder, array $extensions, int $limit): array
    {
        $found = [];

        foreach (array_keys($this->paths) as $path) {
            if (str_starts_with($path, rtrim($folder, '/').'/') && ($extensions === [] || in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true))) {
                $found[] = $path;

                if (count($found) === $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The source file a package starts from: its "source", "main", "module" or "exports" field when that file
     * is in the repository, else src/index.* or index.*.
     *
     * @param  array<string, mixed>  $package
     */
    private function packageEntry(string $folder, array $package): ?string
    {
        $declared = array_filter([
            $package['source'] ?? null,
            $package['main'] ?? null,
            $package['module'] ?? null,
            is_string($package['exports'] ?? null) ? $package['exports'] : null,
            $package['exports']['.']['import'] ?? null,
            $package['exports']['.']['default'] ?? null,
            is_string($package['exports']['.'] ?? null) ? $package['exports']['.'] : null,
        ], 'is_string');

        $candidates = [...array_map(fn (string $file): string => $this->join($folder, ltrim($file, './')), $declared), $this->join($folder, 'src/index'), $this->join($folder, 'index'), $this->join($folder, 'src/main')];

        return $this->existing($candidates);
    }

    /**
     * The first candidate that is an analyzed file, as written or with a source extension.
     *
     * @param  list<string>  $candidates
     */
    private function existing(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $base = preg_replace('/\.(js|mjs|cjs|d\.ts)$/', '', $candidate) ?? $candidate;

            foreach ([$candidate, ...array_map(fn (string $extension): string => "{$base}.{$extension}", ['ts', 'tsx', 'js', 'jsx', 'mjs']), "{$base}/index.ts", "{$base}/index.tsx", "{$base}/index.js"] as $path) {
                if (isset($this->paths[$path])) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * A path inside a folder ('' = the root).
     */
    private function join(string $folder, string $path): string
    {
        return $folder === '' ? $path : "{$folder}/{$path}";
    }
}
