<?php

namespace App\Diagrams;

use App\Enums\TechnologyRole;
use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Finds the technologies a repository really uses, only from its files (no guessing):
 *
 * - every folder (at most 2 levels deep) with a manifest is a project: composer.json, package.json,
 *   pyproject.toml, requirements.txt or go.mod give its language, frameworks and client libraries;
 *   the .env.example next to it gives its database, cache, queue and mail drivers;
 * - Dockerfile / docker-compose files and Kubernetes folders (k8s/, kubernetes/, helm/, charts/)
 *   give how the repository is packaged and which servers (images) it runs next to the code.
 *
 * The file list comes from the code analysis (repository_files), so only files git tracks are read,
 * never symbolic links; the files are read from the clone. Everything recognizable is listed in
 * TechnologyCatalog.
 */
final class TechnologyDetector
{
    /**
     * The manifests that make a folder a project.
     */
    private const MANIFESTS = ['composer.json', 'package.json', 'pyproject.toml', 'requirements.txt', 'go.mod'];

    /**
     * A manifest deeper than this many folders belongs to a library or an example, not to a project.
     */
    private const MAX_PROJECT_DEPTH = 2;

    /**
     * Folders whose manifests are not projects of the repository: tests, examples, documentation.
     */
    private const SKIPPED_FOLDERS = ['tests', 'test', 'spec', '__tests__', 'fixtures', 'examples', 'example', 'samples', 'docs', 'doc'];

    /**
     * Folders with Kubernetes files.
     */
    private const KUBERNETES_FOLDERS = ['k8s', 'kubernetes', 'helm', 'charts', 'kustomize'];

    /**
     * At most this many Kubernetes files are read for their images.
     */
    private const MAX_KUBERNETES_FILES = 40;

    /**
     * A bigger manifest is not read.
     */
    private const MAX_FILE_BYTES = 512 * 1024;

    /**
     * Find the projects and technologies of the repository's clone.
     */
    public function detect(Repository $repository): TechnologyReport
    {
        $paths = array_values(array_filter(
            $repository->files()->orderBy('path')->pluck('path')->all(),
            fn (string $path): bool => ! $this->isSkipped($path),
        ));

        return new TechnologyReport(
            projects: $this->projects($repository, $paths),
            shared: $this->sharedTechnologies($repository, $paths),
        );
    }

    /**
     * One project for each folder with a manifest: the root folder first, then by path.
     *
     * @param  list<string>  $paths
     * @return list<DetectedProject>
     */
    private function projects(Repository $repository, array $paths): array
    {
        $manifestsByFolder = [];

        foreach ($paths as $path) {
            $folder = $this->folderOf($path);

            if (in_array(basename($path), self::MANIFESTS, true) && $this->depthOf($folder) <= self::MAX_PROJECT_DEPTH) {
                $manifestsByFolder[$folder][] = $path;
            }
        }

        ksort($manifestsByFolder, SORT_STRING);

        $projects = [];

        foreach ($manifestsByFolder as $folder => $manifests) {
            $projects[] = $this->project($repository, (string) $folder, $manifests, $paths);
        }

        return $projects;
    }

    /**
     * Read the manifests and the .env.example of one project folder.
     *
     * @param  list<string>  $manifests
     * @param  list<string>  $paths
     */
    private function project(Repository $repository, string $folder, array $manifests, array $paths): DetectedProject
    {
        $technologies = [];

        foreach ($manifests as $manifest) {
            $contents = $this->read($repository, $manifest);

            if ($contents === null) {
                continue;
            }

            $found = match (basename($manifest)) {
                'composer.json' => $this->fromComposer($contents, $manifest),
                'package.json' => $this->fromPackageJson($contents, $manifest),
                'pyproject.toml', 'requirements.txt' => $this->fromPython($contents, $manifest),
                'go.mod' => $this->fromGoModule($contents, $manifest),
            };

            array_push($technologies, ...$found);
        }

        $prefix = $folder === '' ? '' : "{$folder}/";
        $environmentFile = "{$prefix}.env.example";
        $environment = in_array($environmentFile, $paths, true)
            ? $this->parseEnvironment($this->read($repository, $environmentFile) ?? '')
            : [];

        array_push($technologies, ...$this->fromEnvironment($environment, $environmentFile));

        $hasJobs = collect($paths)->contains(fn (string $path): bool => str_starts_with($path, "{$prefix}app/Jobs/"));

        return new DetectedProject(
            folder: $folder,
            technologies: $this->unique($technologies),
            environment: $environment,
            hasQueueJobs: $hasJobs && ($environment['QUEUE_CONNECTION'] ?? null) !== 'sync',
        );
    }

    /**
     * composer.json: PHP with its version constraint, then the packages of "require".
     *
     * @return list<Technology>
     */
    private function fromComposer(string $contents, string $source): array
    {
        $composer = json_decode($contents, true);

        if (! is_array($composer)) {
            return [];
        }

        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $technologies = [TechnologyCatalog::make('php', $source)->withVersion($this->constraint($require['php'] ?? null))];

        foreach ($require as $package => $constraint) {
            $key = TechnologyCatalog::COMPOSER_PACKAGES[$package] ?? null;

            if ($key !== null) {
                $technologies[] = $this->withMajorVersion(TechnologyCatalog::make($key, $source), $constraint);
            }
        }

        return $technologies;
    }

    /**
     * package.json: the packages of "dependencies" and "devDependencies". Node.js is named only when
     * it runs a server (a backend or full-stack framework); pages in the browser are TypeScript or JavaScript.
     *
     * @return list<Technology>
     */
    private function fromPackageJson(string $contents, string $source): array
    {
        $package = json_decode($contents, true);

        if (! is_array($package)) {
            return [];
        }

        $dependencies = [
            ...(is_array($package['dependencies'] ?? null) ? $package['dependencies'] : []),
            ...(is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : []),
        ];

        $technologies = [];

        foreach ($dependencies as $name => $constraint) {
            $key = TechnologyCatalog::npmPackage((string) $name);

            if ($key !== null) {
                $technologies[] = $this->withMajorVersion(TechnologyCatalog::make($key, $source), $constraint);
            }
        }

        $roles = array_map(fn (Technology $technology): TechnologyRole => $technology->role, $technologies);

        if (in_array(TechnologyRole::Backend, $roles, true) || in_array(TechnologyRole::Fullstack, $roles, true)) {
            array_unshift($technologies, TechnologyCatalog::make('nodejs', $source));
        } elseif (in_array(TechnologyRole::Ui, $roles, true) && ! array_key_exists('typescript', $dependencies)) {
            $technologies[] = TechnologyCatalog::make('javascript', $source);
        }

        return $technologies;
    }

    /**
     * pyproject.toml or requirements.txt: Python (with "requires-python" when given) and the packages.
     *
     * A package name is the start of a requirement ("django>=5" → django): every line of
     * requirements.txt; in pyproject.toml the strings of the "dependencies = [...]" lists and the
     * keys of the [tool.poetry.dependencies] tables (never a description that happens to name one).
     *
     * @return list<Technology>
     */
    private function fromPython(string $contents, string $source): array
    {
        preg_match('/^\s*requires-python\s*=\s*["\']([^"\']+)["\']/m', $contents, $version);

        $technologies = [TechnologyCatalog::make('python', $source)->withVersion($version[1] ?? null)];

        $requirements = $contents;

        if (str_ends_with($source, '.toml')) {
            preg_match_all('/dependencies\s*=\s*\[(.*?)\]/s', $contents, $lists);
            preg_match_all('/^\[tool\.poetry(?:\.group\.[\w-]+)?\.dependencies\]\s*$(.*?)(?=^\[|\z)/ms', $contents, $tables);
            preg_match_all('/["\']\s*([A-Za-z0-9][A-Za-z0-9._-]*)/', implode("\n", $lists[1]), $listed);

            $requirements = implode("\n", [...$listed[1], ...$tables[1]]);
        }

        preg_match_all('/^\s*([A-Za-z0-9][A-Za-z0-9._-]*)/m', $requirements, $matches);

        foreach (array_unique(array_map(mb_strtolower(...), $matches[1])) as $package) {
            $key = TechnologyCatalog::PYTHON_PACKAGES[$package] ?? null;

            if ($key !== null) {
                $technologies[] = TechnologyCatalog::make($key, $source);
            }
        }

        return $technologies;
    }

    /**
     * go.mod: Go with the version from the "go" line, then the required modules.
     *
     * @return list<Technology>
     */
    private function fromGoModule(string $contents, string $source): array
    {
        preg_match('/^go\s+([0-9.]+)/m', $contents, $version);

        $technologies = [TechnologyCatalog::make('go', $source)->withVersion($version[1] ?? null)];

        preg_match_all('/^\s*(?:require\s+)?([a-z0-9.-]+\.[a-z]+\/\S+)\s+v\d/m', $contents, $matches);

        foreach ($matches[1] as $module) {
            $key = TechnologyCatalog::goModule($module);

            if ($key !== null) {
                $technologies[] = TechnologyCatalog::make($key, $source);
            }
        }

        return $technologies;
    }

    /**
     * The servers and services a .env.example points to: the database, the cache, the queue,
     * the mailer and S3 files. Drivers that need nothing else ("database", "file", "log") give nothing.
     *
     * @param  array<string, string>  $environment
     * @return list<Technology>
     */
    private function fromEnvironment(array $environment, string $source): array
    {
        $keys = [
            TechnologyCatalog::DATABASE_CONNECTIONS[$environment['DB_CONNECTION'] ?? ''] ?? null,
            TechnologyCatalog::CACHE_STORES[$environment['CACHE_STORE'] ?? $environment['CACHE_DRIVER'] ?? ''] ?? null,
            TechnologyCatalog::CACHE_STORES[$environment['SESSION_DRIVER'] ?? ''] ?? null,
            TechnologyCatalog::QUEUE_CONNECTIONS[$environment['QUEUE_CONNECTION'] ?? ''] ?? null,
            TechnologyCatalog::MAILERS[$environment['MAIL_MAILER'] ?? ''] ?? null,
            ($environment['FILESYSTEM_DISK'] ?? null) === 's3' ? 'aws-s3' : null,
        ];

        return array_map(
            fn (string $key): Technology => TechnologyCatalog::make($key, $source),
            array_values(array_unique(array_filter($keys))),
        );
    }

    /**
     * The technologies of the whole repository: Docker (Dockerfile or compose file), the images
     * of the compose services, and Kubernetes with the images of its files.
     *
     * @param  list<string>  $paths
     * @return list<Technology>
     */
    private function sharedTechnologies(Repository $repository, array $paths): array
    {
        $technologies = [];
        $kubernetesFiles = [];

        foreach ($paths as $path) {
            $name = basename($path);
            $shallow = $this->depthOf($this->folderOf($path)) <= self::MAX_PROJECT_DEPTH;

            if ($shallow && in_array($name, ['Dockerfile', 'Containerfile'], true)) {
                $technologies[] = TechnologyCatalog::make('docker', $path);
            }

            if ($shallow && preg_match('/\A(docker-)?compose[\w.-]*\.ya?ml\z/', $name) === 1) {
                $technologies[] = TechnologyCatalog::make('docker', $path);
                array_push($technologies, ...$this->fromImages($this->read($repository, $path) ?? '', $path));
            }

            $folders = array_map(mb_strtolower(...), explode('/', $this->folderOf($path)));

            if (array_intersect($folders, self::KUBERNETES_FOLDERS) !== [] && preg_match('/\.ya?ml\z/', $name) === 1) {
                $kubernetesFiles[] = $path;
            }
        }

        if ($kubernetesFiles !== []) {
            $technologies[] = TechnologyCatalog::make('kubernetes', $this->folderOf($kubernetesFiles[0]).'/');

            foreach (array_slice($kubernetesFiles, 0, self::MAX_KUBERNETES_FILES) as $path) {
                array_push($technologies, ...$this->fromImages($this->read($repository, $path) ?? '', $path));
            }
        }

        return $this->unique($technologies);
    }

    /**
     * The technologies of the "image:" lines of a compose or Kubernetes file.
     * "docker.io/bitnami/postgresql:16" → postgresql: the registry, the owner and the tag are dropped.
     *
     * @return list<Technology>
     */
    private function fromImages(string $contents, string $source): array
    {
        preg_match_all('/^\s*-?\s*image:\s*["\']?([^\s"\'#]+)/m', $contents, $matches);

        $technologies = [];

        foreach ($matches[1] as $image) {
            $name = Str::of($image)->before('@')->afterLast('/')->before(':')->lower()->toString();
            $key = TechnologyCatalog::DOCKER_IMAGES[$name] ?? null;

            if ($key !== null) {
                $technologies[] = TechnologyCatalog::make($key, $source);
            }
        }

        return $technologies;
    }

    /**
     * The settings of a .env file: "KEY=value" lines; comments and empty values are skipped,
     * and quotes around a value are removed.
     *
     * @return array<string, string>
     */
    private function parseEnvironment(string $contents): array
    {
        preg_match_all('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/m', $contents, $matches, PREG_SET_ORDER);

        $environment = [];

        foreach ($matches as [, $key, $value]) {
            $value = trim($value, '"\'');

            if ($value !== '') {
                $environment[$key] = mb_strtolower($value);
            }
        }

        return $environment;
    }

    /**
     * A version constraint as written ("^8.3"), or null.
     */
    private function constraint(mixed $constraint): ?string
    {
        return is_string($constraint) && $constraint !== '' ? $constraint : null;
    }

    /**
     * Frameworks show their major version ("^13.17" → "Laravel 13"); other technologies stay as they are.
     */
    private function withMajorVersion(Technology $technology, mixed $constraint): Technology
    {
        $roles = [TechnologyRole::Backend, TechnologyRole::Fullstack, TechnologyRole::Ui];

        if (! in_array($technology->role, $roles, true) || ! is_string($constraint) || preg_match('/(\d+)/', $constraint, $major) !== 1) {
            return $technology;
        }

        return $technology->withVersion($major[1]);
    }

    /**
     * Keep the first technology of each key.
     *
     * @param  list<Technology>  $technologies
     * @return list<Technology>
     */
    private function unique(array $technologies): array
    {
        $unique = [];

        foreach ($technologies as $technology) {
            $unique[$technology->key] ??= $technology;
        }

        return array_values($unique);
    }

    /**
     * The contents of a file of the clone, or null when it is missing or too big.
     */
    private function read(Repository $repository, string $path): ?string
    {
        $fullPath = $repository->clonePath().'/'.$path;

        if (! is_file($fullPath) || is_link($fullPath) || filesize($fullPath) > self::MAX_FILE_BYTES) {
            return null;
        }

        return File::get($fullPath);
    }

    /**
     * Whether a file is in a folder whose manifests are not projects (tests, examples, docs).
     */
    public function isSkipped(string $path): bool
    {
        $folders = array_map(mb_strtolower(...), explode('/', $this->folderOf($path)));

        return array_intersect($folders, self::SKIPPED_FOLDERS) !== [];
    }

    /**
     * The folder of a file ('' for the root folder).
     */
    private function folderOf(string $path): string
    {
        $folder = dirname($path);

        return $folder === '.' ? '' : $folder;
    }

    /**
     * How many folders deep a folder is ('' = 0, "packages/core" = 2).
     */
    private function depthOf(string $folder): int
    {
        return $folder === '' ? 0 : substr_count($folder, '/') + 1;
    }
}
