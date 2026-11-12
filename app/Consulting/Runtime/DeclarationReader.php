<?php

namespace App\Consulting\Runtime;

use App\Diagrams\TechnologyDetector;
use App\Models\Repository;
use Illuminate\Support\Facades\File;

/**
 * Finds which runtime and framework versions a repository declares, with the file and line of each.
 *
 * The files to look at come from the same list as the technology detection of the diagrams
 * (repository_files, without test, example and docs folders: TechnologyDetector::isSkipped). That detector
 * keeps only a major version and no line numbers, so the manifests are read again here, once, for this purpose.
 *
 * Runtimes: composer.json (require.php, config.platform.php), package.json engines.node, .nvmrc,
 * .node-version, .python-version, runtime.txt, pyproject.toml requires-python, go.mod, .ruby-version,
 * Gemfile, .java-version, pom.xml, Gradle files, *.csproj, .tool-versions and the FROM lines of Dockerfiles.
 * Frameworks: the version installed according to composer.lock, package-lock.json, poetry.lock,
 * requirements.txt and Gemfile.lock; without a lock file, the lowest version the manifest allows.
 */
final class DeclarationReader
{
    /**
     * Files bigger than this are not read; at most this many declarations are kept.
     */
    private const MAX_FILE_BYTES = 1024 * 1024;

    private const MAX_DECLARATIONS = 40;

    /**
     * Manifests deeper than this many folders are ignored (monorepo packages are found, vendored copies are not).
     */
    private const MAX_DEPTH = 3;

    /**
     * The Docker image names that carry a runtime, and the runtime they mean.
     */
    private const IMAGES = [
        'php' => 'php', 'node' => 'nodejs', 'python' => 'python', 'golang' => 'go', 'ruby' => 'ruby',
        'eclipse-temurin' => 'java', 'openjdk' => 'java', 'amazoncorretto' => 'java',
        'mcr.microsoft.com/dotnet/sdk' => 'dotnet', 'mcr.microsoft.com/dotnet/aspnet' => 'dotnet', 'mcr.microsoft.com/dotnet/runtime' => 'dotnet',
    ];

    /**
     * The names asdf and mise use in .tool-versions.
     */
    private const TOOL_VERSIONS = ['nodejs' => 'nodejs', 'python' => 'python', 'ruby' => 'ruby', 'golang' => 'go', 'php' => 'php'];

    public function __construct(private TechnologyDetector $detector) {}

    /**
     * The declarations of the repository's clone, in a stable order (path, then line).
     *
     * @return list<Declaration>
     */
    public function read(Repository $repository): array
    {
        $paths = $repository->files()->orderBy('path')->pluck('path')->all();
        $declarations = [];

        foreach ($paths as $path) {
            if (! self::isCandidate(basename($path)) || $this->detector->isSkipped($path) || substr_count($path, '/') > self::MAX_DEPTH) {
                continue;
            }

            $contents = $this->contents($repository, $path);

            if ($contents === null) {
                continue;
            }

            array_push($declarations, ...$this->fromFile($path, $contents, $paths));
        }

        $unique = [];

        foreach ($declarations as $declaration) {
            $unique["{$declaration->product}|{$declaration->path}|{$declaration->version}"] ??= $declaration;
        }

        return array_slice(array_values($unique), 0, self::MAX_DECLARATIONS);
    }

    /**
     * Whether a file name is one of the manifests, lock files or version files the reader knows (so no other file is opened).
     */
    private static function isCandidate(string $name): bool
    {
        return in_array($name, [
            'composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.nvmrc', '.node-version', '.python-version',
            '.ruby-version', '.java-version', 'runtime.txt', 'pyproject.toml', 'poetry.lock', 'requirements.txt', 'go.mod',
            'Gemfile', 'Gemfile.lock', 'pom.xml', 'build.gradle', 'build.gradle.kts', '.tool-versions', 'Dockerfile',
        ], true) || str_ends_with($name, '.csproj') || str_starts_with($name, 'Dockerfile.') || str_ends_with($name, '.Dockerfile');
    }

    /**
     * @param  list<string>  $paths  every file of the repository (to know whether a lock file exists)
     * @return list<Declaration>
     */
    private function fromFile(string $path, string $contents, array $paths): array
    {
        $name = basename($path);
        $folder = dirname($path) === '.' ? '' : dirname($path).'/';

        return match (true) {
            $name === 'composer.json' => $this->fromComposerJson($path, $contents, in_array("{$folder}composer.lock", $paths, true)),
            $name === 'composer.lock' => $this->fromComposerLock($path, $contents),
            $name === 'package.json' => $this->fromPackageJson($path, $contents, in_array("{$folder}package-lock.json", $paths, true)),
            $name === 'package-lock.json' => $this->fromPackageLock($path, $contents),
            $name === '.nvmrc', $name === '.node-version' => $this->fromVersionFile($path, $contents, 'nodejs'),
            $name === '.python-version' => $this->fromVersionFile($path, $contents, 'python'),
            $name === '.ruby-version' => $this->fromVersionFile($path, $contents, 'ruby'),
            $name === '.java-version' => $this->fromVersionFile($path, $contents, 'java'),
            $name === 'runtime.txt' => $this->fromRuntimeTxt($path, $contents),
            $name === 'pyproject.toml' => $this->fromPyproject($path, $contents),
            $name === 'poetry.lock' => $this->fromPoetryLock($path, $contents),
            $name === 'requirements.txt' => $this->fromRequirements($path, $contents),
            $name === 'go.mod' => $this->fromGoMod($path, $contents),
            $name === 'Gemfile' => $this->fromGemfile($path, $contents),
            $name === 'Gemfile.lock' => $this->fromGemfileLock($path, $contents),
            $name === 'pom.xml' => $this->fromPom($path, $contents),
            in_array($name, ['build.gradle', 'build.gradle.kts'], true) => $this->fromGradle($path, $contents),
            str_ends_with($name, '.csproj') => $this->fromCsproj($path, $contents),
            $name === '.tool-versions' => $this->fromToolVersions($path, $contents),
            $name === 'Dockerfile', str_starts_with($name, 'Dockerfile.'), str_ends_with($name, '.Dockerfile') => $this->fromDockerfile($path, $contents),
            default => [],
        };
    }

    /**
     * require.php and config.platform.php of composer.json, and the framework constraints when no lock file exists.
     *
     * @return list<Declaration>
     */
    private function fromComposerJson(string $path, string $contents, bool $hasLock): array
    {
        $json = json_decode($contents, true);

        if (! is_array($json)) {
            return [];
        }

        $found = [];
        $require = is_array($json['require'] ?? null) ? $json['require'] : [];
        $platform = $json['config']['platform']['php'] ?? null;

        if (is_string($require['php'] ?? null)) {
            $found[] = $this->constraint('php', $require['php'], $path, self::lineOf($contents, '/"php"\s*:/'));
        }

        if (is_string($platform)) {
            $found[] = $this->constraint('php', $platform, $path, self::lineOf($contents, '/"php"\s*:/', after: '/"platform"/'));
        }

        if (! $hasLock) {
            foreach (['laravel/framework' => 'laravel', 'symfony/framework-bundle' => 'symfony', 'symfony/symfony' => 'symfony'] as $package => $product) {
                if (is_string($require[$package] ?? null)) {
                    $found[] = $this->constraint($product, $require[$package], $path, self::lineOf($contents, '/"'.preg_quote($package, '/').'"\s*:/'));
                }
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * The installed version of the frameworks, from composer.lock ("packages" and "packages-dev").
     *
     * @return list<Declaration>
     */
    private function fromComposerLock(string $path, string $contents): array
    {
        $json = json_decode($contents, true);
        $found = [];

        foreach (['laravel/framework' => 'laravel', 'symfony/framework-bundle' => 'symfony', 'symfony/symfony' => 'symfony'] as $package => $product) {
            foreach ($json['packages'] ?? [] as $entry) {
                if (($entry['name'] ?? null) === $package && is_string($entry['version'] ?? null)) {
                    $found[] = $this->pinned($product, $entry['version'], $path, self::lineOf($contents, '/"name"\s*:\s*"'.preg_quote($package, '/').'"/'));

                    break;
                }
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * engines.node, and the framework constraints of "dependencies" when no lock file exists.
     *
     * @return list<Declaration>
     */
    private function fromPackageJson(string $path, string $contents, bool $hasLock): array
    {
        $json = json_decode($contents, true);

        if (! is_array($json)) {
            return [];
        }

        $found = [];

        if (is_string($json['engines']['node'] ?? null)) {
            $found[] = $this->constraint('nodejs', $json['engines']['node'], $path, self::lineOf($contents, '/"node"\s*:/', after: '/"engines"/'));
        }

        if (! $hasLock) {
            $dependencies = [...(array) ($json['devDependencies'] ?? []), ...(array) ($json['dependencies'] ?? [])];

            foreach (Products::npmPackages() as $package => $product) {
                if (is_string($dependencies[$package] ?? null)) {
                    $found[] = $this->constraint($product, $dependencies[$package], $path, self::lineOf($contents, '/"'.preg_quote($package, '/').'"\s*:/'));
                }
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * The installed version of the frameworks, from package-lock.json (lockfile version 2 and 3: "packages").
     *
     * @return list<Declaration>
     */
    private function fromPackageLock(string $path, string $contents): array
    {
        $json = json_decode($contents, true);
        $found = [];

        foreach (Products::npmPackages() as $package => $product) {
            $version = $json['packages']["node_modules/{$package}"]['version'] ?? $json['dependencies'][$package]['version'] ?? null;

            if (is_string($version)) {
                $found[] = $this->pinned($product, $version, $path, self::lineOf($contents, '/"(node_modules\/)?'.preg_quote($package, '/').'"\s*:\s*\{/'));
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * A file that holds only a version: .nvmrc ("v20.11.0", "20", "lts/*" is ignored), .python-version, .ruby-version…
     *
     * @return list<Declaration>
     */
    private function fromVersionFile(string $path, string $contents, string $product): array
    {
        foreach (explode("\n", $contents) as $index => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // "3.12.1", "v20", "ruby-3.2.2", "1.8" (Java 8), "openjdk64-17.0.2": the first version-looking part.
            if (preg_match('/(?:^|[-\s])v?(\d+(?:\.\d+){0,2})/', $line, $matches) === 1) {
                $declaration = $this->constraint($product, $matches[1], $path, $index + 1, $line);

                return $declaration === null ? [] : [$declaration];
            }

            return [];
        }

        return [];
    }

    /**
     * runtime.txt of Heroku-style hosts: "python-3.11.4".
     *
     * @return list<Declaration>
     */
    private function fromRuntimeTxt(string $path, string $contents): array
    {
        if (preg_match('/^\s*python-(\d+(?:\.\d+){0,2})/mi', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        return array_values(array_filter([$this->constraint('python', $matches[1][0], $path, substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1)]));
    }

    /**
     * requires-python of pyproject.toml, and the framework constraint of a Django dependency.
     *
     * @return list<Declaration>
     */
    private function fromPyproject(string $path, string $contents): array
    {
        $found = [];

        if (preg_match('/^\s*requires-python\s*=\s*["\']([^"\']+)["\']/mi', $contents, $matches) === 1) {
            $found[] = $this->constraint('python', $matches[1], $path, self::lineOf($contents, '/^\s*requires-python\s*=/'));
        }

        // poetry: python = "^3.10" in [tool.poetry.dependencies]
        if (preg_match('/^\s*python\s*=\s*["\']([^"\']+)["\']/mi', $contents, $matches) === 1) {
            $found[] = $this->constraint('python', $matches[1], $path, self::lineOf($contents, '/^\s*python\s*=/'));
        }

        return array_values(array_filter($found));
    }

    /**
     * The installed Django, from poetry.lock: a [[package]] with name = "django" and the version under it.
     *
     * @return list<Declaration>
     */
    private function fromPoetryLock(string $path, string $contents): array
    {
        if (preg_match('/^name\s*=\s*"django"\s*\nversion\s*=\s*"([^"]+)"/mi', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        return array_values(array_filter([$this->pinned('django', $matches[1][0], $path, substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1)]));
    }

    /**
     * Django in requirements.txt: "Django==5.2.1" is the installed version, "Django>=4.2" the lowest allowed.
     *
     * @return list<Declaration>
     */
    private function fromRequirements(string $path, string $contents): array
    {
        if (preg_match('/^\s*django\s*(==|>=|~=|>|<=|<)?\s*([0-9][^\s;,#]*)?/mi', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1 || ! isset($matches[2][0])) {
            return [];
        }

        $line = substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;
        $operator = $matches[1][0];

        if ($operator === '<' || $operator === '<=') {
            return [];
        }

        return array_values(array_filter([$operator === '==' ? $this->pinned('django', $matches[2][0], $path, $line) : $this->constraint('django', $matches[2][0], $path, $line, "{$operator}{$matches[2][0]}")]));
    }

    /**
     * "go 1.22.0" of go.mod. Since Go 1.21 this line is the minimum Go version, so it counts as a range.
     *
     * @return list<Declaration>
     */
    private function fromGoMod(string $path, string $contents): array
    {
        if (preg_match('/^go\s+(\d+\.\d+(?:\.\d+)?)/m', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        return [new Declaration('go', $matches[1][0], $matches[1][0], true, $path, substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1)];
    }

    /**
     * `ruby "3.2.2"` or `ruby "~> 3.1"` in a Gemfile.
     *
     * @return list<Declaration>
     */
    private function fromGemfile(string $path, string $contents): array
    {
        if (preg_match('/^\s*ruby\s+["\']([^"\']+)["\']/m', $contents, $matches) !== 1) {
            return [];
        }

        return array_values(array_filter([$this->constraint('ruby', $matches[1], $path, self::lineOf($contents, '/^\s*ruby\s+["\']/'))]));
    }

    /**
     * The installed Rails, from Gemfile.lock: "    rails (7.1.3)".
     *
     * @return list<Declaration>
     */
    private function fromGemfileLock(string $path, string $contents): array
    {
        if (preg_match('/^    rails \((\d+(?:\.\d+)*)\)/m', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        return array_values(array_filter([$this->pinned('rails', $matches[1][0], $path, substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1)]));
    }

    /**
     * The Java version of a Maven project: java.version, maven.compiler.release/source/target.
     *
     * @return list<Declaration>
     */
    private function fromPom(string $path, string $contents): array
    {
        if (preg_match('/<(?:java\.version|maven\.compiler\.release|maven\.compiler\.source|maven\.compiler\.target)>\s*(\d+(?:\.\d+)?)\s*</', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        return array_values(array_filter([$this->javaVersion($matches[1][0], $path, substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1)]));
    }

    /**
     * The Java version of a Gradle project: JavaLanguageVersion.of(17), JavaVersion.VERSION_17, sourceCompatibility.
     *
     * @return list<Declaration>
     */
    private function fromGradle(string $path, string $contents): array
    {
        $pattern = '/(?:JavaLanguageVersion\.of\(\s*(\d+)\s*\)|JavaVersion\.VERSION_(\d+(?:_\d+)?)|sourceCompatibility\s*=\s*[\'"]?(\d+(?:\.\d+)?)[\'"]?)/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $version = str_replace('_', '.', collect([1, 2, 3])->map(fn (int $group): string => $matches[$group][0] ?? '')->first(fn (string $value): bool => $value !== ''));

        return array_values(array_filter([$this->javaVersion($version, $path, substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1)]));
    }

    /**
     * Java 8 is written "1.8": the version is the number after "1.".
     */
    private function javaVersion(string $version, string $path, int $line): ?Declaration
    {
        $normalized = str_starts_with($version, '1.') ? substr($version, 2) : $version;

        return $this->constraint('java', $normalized, $path, $line, $version);
    }

    /**
     * <TargetFramework>net8.0</TargetFramework> of a .NET project (net5.0 and newer; netcoreapp and netstandard are other products).
     *
     * @return list<Declaration>
     */
    private function fromCsproj(string $path, string $contents): array
    {
        if (preg_match('/<TargetFrameworks?>[^<]*?\bnet(\d+\.\d+)\b/', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1 || (int) $matches[1][0] < 5) {
            return [];
        }

        return array_values(array_filter([$this->constraint('dotnet', $matches[1][0], $path, substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1, 'net'.$matches[1][0])]));
    }

    /**
     * .tool-versions of asdf and mise: "nodejs 20.11.0".
     *
     * @return list<Declaration>
     */
    private function fromToolVersions(string $path, string $contents): array
    {
        $found = [];

        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match('/^\s*(\w+)\s+v?(\d+(?:\.\d+){0,2})\b/', $line, $matches) === 1 && isset(self::TOOL_VERSIONS[$matches[1]])) {
                $found[] = $this->constraint(self::TOOL_VERSIONS[$matches[1]], $matches[2], $path, $index + 1);
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * The FROM lines of a Dockerfile: "FROM php:8.2-fpm AS base", "FROM node:20.11.0-alpine". Tags that are not
     * a version ("latest", "alpine") and images built from ARGs are ignored. Stages that use an earlier stage are skipped too.
     *
     * @return list<Declaration>
     */
    private function fromDockerfile(string $path, string $contents): array
    {
        $found = [];

        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match('/^\s*FROM\s+(?:--platform=\S+\s+)?([^\s:@]+):v?(\d+(?:\.\d+){0,2})/i', $line, $matches) !== 1) {
                continue;
            }

            $product = self::IMAGES[strtolower($matches[1])] ?? null;

            if ($product !== null) {
                $found[] = $this->constraint($product, $matches[2], $path, $index + 1, trim(preg_replace('/(@sha256:\S+|\s+AS\s+.*$)/i', '', preg_replace('/^\s*FROM\s+(--platform=\S+\s+)?/i', '', $line))));
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * A declaration from text that may be a range.
     */
    private function constraint(string $product, string $text, string $path, ?int $line, ?string $declared = null): ?Declaration
    {
        $lowest = VersionConstraint::lowest($text);

        return $lowest === null ? null : new Declaration($product, $declared ?? $text, $lowest['version'], $lowest['is_range'], $path, $line);
    }

    /**
     * A declaration of a version a lock file says is installed ("v13.0.1" gives 13.0.1).
     */
    private function pinned(string $product, string $version, string $path, ?int $line): ?Declaration
    {
        $clean = ltrim($version, 'vV');

        return preg_match('/^\d+(\.\d+)*/', $clean, $matches) === 1 ? new Declaration($product, $version, $matches[0], false, $path, $line) : null;
    }

    /**
     * The line number of the first line matching a pattern, optionally only after the first line matching $after.
     */
    private static function lineOf(string $contents, string $pattern, ?string $after = null): ?int
    {
        $started = $after === null;

        foreach (explode("\n", $contents) as $index => $line) {
            if (! $started) {
                $started = preg_match($after, $line) === 1;

                continue;
            }

            if (preg_match($pattern, $line) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The contents of a file of the clone, or null when it is missing, a link or too big.
     */
    private function contents(Repository $repository, string $path): ?string
    {
        $fullPath = $repository->clonePath().'/'.$path;

        if (! is_file($fullPath) || is_link($fullPath) || filesize($fullPath) > self::MAX_FILE_BYTES) {
            return null;
        }

        return File::get($fullPath);
    }
}
