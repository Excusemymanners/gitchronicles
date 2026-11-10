<?php

namespace App\Documentation;

use Illuminate\Support\Str;

/**
 * What the files of the root folder say about a project: its package manifests (composer.json,
 * package.json, pyproject.toml, go.mod, ...), the commands they define, what must be installed,
 * the test setup, the license and the files that start the program.
 *
 * Every fact has a source (the file it was read from). A command is listed only when a manifest
 * defines it (a script) or when it is the standard command of that manifest ("composer install"
 * for composer.json, "go build ./..." for go.mod): GitChronicle never invents how to run a project.
 */
final class ProjectFacts
{
    /**
     * At most this many dependencies are listed per manifest section.
     */
    public const MAX_DEPENDENCIES = 25;

    /**
     * At most this many scripts (or Makefile targets) are taken from one file.
     */
    public const MAX_SCRIPTS = 20;

    /**
     * How much of a manifest that is not JSON (pyproject.toml, Cargo.toml, ...) is kept as text.
     */
    public const EXCERPT_CHARACTERS = 3000;

    /**
     * Files known to start a program, by their path (a convention of the framework or language).
     */
    private const CONVENTIONAL_ENTRY_POINTS = [
        'artisan' => 'linia de comandă Laravel (Artisan)',
        'public/index.php' => 'intrarea aplicației web (PHP)',
        'index.php' => 'intrarea aplicației web (PHP)',
        'routes/web.php' => 'rutele web (Laravel)',
        'routes/api.php' => 'rutele API (Laravel)',
        'manage.py' => 'comenzile Django',
        'main.py' => 'programul Python',
        'app.py' => 'aplicația Python',
        'main.go' => 'programul Go',
        'src/main.rs' => 'programul Rust',
        'src/lib.rs' => 'biblioteca Rust',
        'index.js' => 'intrarea codului JavaScript',
        'index.ts' => 'intrarea codului TypeScript',
        'src/index.js' => 'intrarea codului JavaScript',
        'src/index.ts' => 'intrarea codului TypeScript',
        'src/index.tsx' => 'intrarea interfeței (React)',
        'src/main.js' => 'intrarea codului JavaScript',
        'src/main.ts' => 'intrarea codului TypeScript',
        'src/main.tsx' => 'intrarea interfeței (React)',
        'resources/js/app.js' => 'intrarea interfeței (JavaScript)',
        'resources/js/app.ts' => 'intrarea interfeței (TypeScript)',
        'resources/js/app.tsx' => 'intrarea interfeței (React)',
        'resources/js/app.jsx' => 'intrarea interfeței (React)',
    ];

    /**
     * npm runs these scripts by itself while installing or publishing, so they are not commands to type.
     */
    private const NPM_LIFECYCLE_SCRIPTS = [
        'preinstall', 'install', 'postinstall', 'prepare', 'prepublish', 'prepublishOnly',
        'prepack', 'postpack', 'dependencies',
    ];

    /**
     * Test libraries recognized in package.json, with their display names.
     */
    private const JAVASCRIPT_TEST_LIBRARIES = [
        'jest' => 'Jest',
        'vitest' => 'Vitest',
        'mocha' => 'Mocha',
        '@playwright/test' => 'Playwright',
        'cypress' => 'Cypress',
    ];

    /**
     * @var list<array{path: string, kind: string, summary: array<string, mixed>}>
     */
    public array $manifests = [];

    /**
     * @var list<array{command: string, purpose: string, source: string, detail: string|null}>
     */
    public array $commands = [];

    /**
     * @var list<array{name: string, version: string|null, source: string}>
     */
    public array $requirements = [];

    /**
     * @var list<array{path: string, kind: string}>
     */
    public array $entryPoints = [];

    /**
     * @var array{frameworks: list<array{name: string, source: string}>, test_files: int}|null
     */
    public ?array $tests = null;

    /**
     * @var array{name: string, source: string}|null
     */
    public ?array $license = null;

    /**
     * @var list<array{name: string, source: string}>
     */
    private array $testFrameworks = [];

    /**
     * Every analyzed path, as keys, to check quickly whether a file exists.
     *
     * @var array<string, int>
     */
    private array $paths;

    /**
     * Read the facts from the clone.
     *
     * @param  list<string>  $paths  every file path saved by the code analysis
     */
    public function __construct(private CloneFiles $files, array $paths)
    {
        $this->paths = array_flip($paths);

        $this->readComposer();
        $this->readPackageJson();
        $this->readPython();
        $this->readGo();
        $this->readOtherBuildFiles();
        $this->readTestSetup();
        $this->readLicense();
        $this->findEntryPoints();
    }

    /**
     * composer.json: PHP and its packages, `composer install` and the scripts.
     */
    private function readComposer(): void
    {
        $composer = $this->json('composer.json');

        if ($composer === null) {
            return;
        }

        $require = $this->stringMap($composer['require'] ?? []);
        $scripts = $this->composerScripts($composer['scripts'] ?? []);

        $this->manifests[] = ['path' => 'composer.json', 'kind' => 'Composer (PHP)', 'summary' => array_filter([
            'name' => $this->string($composer['name'] ?? null),
            'description' => $this->string($composer['description'] ?? null),
            'license' => $this->licenseField($composer['license'] ?? null),
            'require' => array_slice($require, 0, self::MAX_DEPENDENCIES, true),
            'require_dev' => array_slice($this->stringMap($composer['require-dev'] ?? []), 0, self::MAX_DEPENDENCIES, true),
            'scripts' => $scripts,
            'bin' => $this->stringList($composer['bin'] ?? []),
        ])];

        $this->requirement('PHP', $require['php'] ?? null, 'composer.json');
        $this->requirement('Composer', null, 'composer.json');
        $this->command('composer install', 'install', 'composer.json');

        foreach ($scripts as $name => $detail) {
            $this->command("composer run {$name}", self::purposeOf($name), 'composer.json', $detail);
        }

        foreach ($this->stringList($composer['bin'] ?? []) as $bin) {
            $this->entryPoint($bin, 'executabil declarat în composer.json');
        }

        $this->licenseFromManifest($composer['license'] ?? null, 'composer.json');
    }

    /**
     * package.json: Node.js, the package manager (from its lock file), the scripts and the dependencies.
     */
    private function readPackageJson(): void
    {
        $package = $this->json('package.json');

        if ($package === null) {
            return;
        }

        $manager = $this->packageManager($package);
        $dependencies = $this->stringMap($package['dependencies'] ?? []);
        $devDependencies = $this->stringMap($package['devDependencies'] ?? []);
        $scripts = $this->npmScripts($package['scripts'] ?? []);

        $this->manifests[] = ['path' => 'package.json', 'kind' => 'npm (JavaScript/TypeScript)', 'summary' => array_filter([
            'name' => $this->string($package['name'] ?? null),
            'description' => $this->string($package['description'] ?? null),
            'license' => $this->licenseField($package['license'] ?? null),
            'package_manager' => $manager['name'],
            'scripts' => $scripts,
            'dependencies' => array_slice($dependencies, 0, self::MAX_DEPENDENCIES, true),
            'dev_dependencies' => array_slice($devDependencies, 0, self::MAX_DEPENDENCIES, true),
            'main' => $this->string($package['main'] ?? null),
            'engines' => $this->stringMap($package['engines'] ?? []),
        ])];

        $this->requirement('Node.js', $this->stringMap($package['engines'] ?? [])['node'] ?? null, 'package.json');
        $this->requirement($manager['name'], $manager['version'], $manager['source']);
        $this->command("{$manager['name']} install", 'install', $manager['source']);

        foreach ($scripts as $name => $detail) {
            $command = $manager['name'] === 'yarn' ? "yarn {$name}" : "{$manager['name']} run {$name}";
            $this->command($command, self::purposeOf($name), 'package.json', $detail);
        }

        foreach (self::JAVASCRIPT_TEST_LIBRARIES as $library => $displayName) {
            if (isset($dependencies[$library]) || isset($devDependencies[$library])) {
                $this->testFramework($displayName, 'package.json');
            }
        }

        if (($main = $this->string($package['main'] ?? null)) !== null) {
            $this->entryPoint(ltrim($main, './'), 'intrarea pachetului (main din package.json)');
        }

        $bins = is_string($package['bin'] ?? null) ? [$package['bin']] : $this->stringMap($package['bin'] ?? []);

        foreach ($bins as $bin) {
            $this->entryPoint(ltrim($bin, './'), 'executabil declarat în package.json');
        }

        $this->licenseFromManifest($package['license'] ?? null, 'package.json');
    }

    /**
     * pyproject.toml, requirements.txt, setup.py and pytest. TOML is not parsed (no TOML library):
     * a few lines are read with regular expressions and the beginning of the file is kept as text.
     */
    private function readPython(): void
    {
        $pyproject = $this->files->read('pyproject.toml');

        if ($pyproject !== null) {
            $requiresPython = $this->firstMatch('/^\s*requires-python\s*=\s*["\']([^"\']+)["\']/m', $pyproject);

            $this->manifests[] = ['path' => 'pyproject.toml', 'kind' => 'Python', 'summary' => array_filter([
                'name' => $this->firstMatch('/^\s*name\s*=\s*["\']([^"\']+)["\']/m', $pyproject),
                'requires_python' => $requiresPython,
                'excerpt' => Str::limit($pyproject, self::EXCERPT_CHARACTERS, '…'),
            ])];

            $this->requirement('Python', $requiresPython, 'pyproject.toml');

            if (preg_match('/^\[tool\.poetry\]/m', $pyproject) === 1) {
                $this->command('poetry install', 'install', 'pyproject.toml');
            } elseif ($this->files->exists('uv.lock')) {
                $this->command('uv sync', 'install', 'uv.lock');
            } elseif (preg_match('/^\[(project|build-system)\]/m', $pyproject) === 1) {
                $this->command('pip install .', 'install', 'pyproject.toml');
            }

            if (preg_match('/^\[tool\.pytest/m', $pyproject) === 1) {
                $this->testFramework('pytest', 'pyproject.toml');
            }
        }

        $requirements = $this->files->read('requirements.txt');

        if ($requirements !== null) {
            $packages = array_values(array_filter(
                array_map(trim(...), preg_split('/\R/', $requirements) ?: []),
                fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#') && ! str_starts_with($line, '-'),
            ));

            $this->manifests[] = ['path' => 'requirements.txt', 'kind' => 'pip (Python)', 'summary' => [
                'packages' => array_slice($packages, 0, self::MAX_DEPENDENCIES),
            ]];

            $this->requirement('Python', null, 'requirements.txt');
            $this->command('pip install -r requirements.txt', 'install', 'requirements.txt');

            if (preg_match('/^pytest\b/mi', $requirements) === 1) {
                $this->testFramework('pytest', 'requirements.txt');
            }
        }

        if ($pyproject === null && $this->files->exists('setup.py')) {
            $this->requirement('Python', null, 'setup.py');
            $this->command('pip install .', 'install', 'setup.py');
        }

        foreach (['pytest.ini', 'conftest.py'] as $pytestFile) {
            if ($this->files->exists($pytestFile)) {
                $this->testFramework('pytest', $pytestFile);
            }
        }
    }

    /**
     * go.mod: the module, the Go version, `go build ./...` and, when there are test files, `go test ./...`.
     */
    private function readGo(): void
    {
        $goMod = $this->files->read('go.mod');

        if ($goMod === null) {
            return;
        }

        $version = $this->firstMatch('/^go\s+(\S+)/m', $goMod);

        $this->manifests[] = ['path' => 'go.mod', 'kind' => 'Go', 'summary' => array_filter([
            'module' => $this->firstMatch('/^module\s+(\S+)/m', $goMod),
            'go' => $version,
            'excerpt' => Str::limit($goMod, self::EXCERPT_CHARACTERS, '…'),
        ])];

        $this->requirement('Go', $version, 'go.mod');
        $this->command('go build ./...', 'build', 'go.mod');

        if ($this->anyPath(fn (string $path): bool => str_ends_with($path, '_test.go'))) {
            $this->command('go test ./...', 'test', 'go.mod');
            $this->testFramework('go test', 'fișierele *_test.go');
        }
    }

    /**
     * Cargo, Bundler, Maven, Gradle, Make and Docker: their standard commands, when their file exists.
     */
    private function readOtherBuildFiles(): void
    {
        if (($cargo = $this->files->read('Cargo.toml')) !== null) {
            $this->manifests[] = ['path' => 'Cargo.toml', 'kind' => 'Cargo (Rust)', 'summary' => ['excerpt' => Str::limit($cargo, self::EXCERPT_CHARACTERS, '…')]];
            $this->requirement('Rust (Cargo)', null, 'Cargo.toml');
            $this->command('cargo build', 'build', 'Cargo.toml');

            if (isset($this->paths['src/main.rs'])) {
                $this->command('cargo run', 'run', 'Cargo.toml');
            }

            if ($this->anyPath(fn (string $path): bool => str_starts_with($path, 'tests/'))) {
                $this->command('cargo test', 'test', 'Cargo.toml');
            }
        }

        if ($this->files->exists('Gemfile')) {
            $this->requirement('Ruby (Bundler)', null, 'Gemfile');
            $this->command('bundle install', 'install', 'Gemfile');
        }

        if ($this->files->exists('pom.xml')) {
            $this->requirement('Java (Maven)', null, 'pom.xml');
            $this->command('mvn package', 'build', 'pom.xml');
        }

        foreach (['build.gradle', 'build.gradle.kts'] as $gradleFile) {
            if ($this->files->exists($gradleFile)) {
                $this->requirement('Java (Gradle)', null, $gradleFile);
                $this->command($this->files->exists('gradlew') ? './gradlew build' : 'gradle build', 'build', $gradleFile);
            }
        }

        if (($makefile = $this->files->read('Makefile')) !== null) {
            preg_match_all('/^([A-Za-z0-9][A-Za-z0-9_.-]*)\s*:(?!=)/m', $makefile, $matches);

            foreach (array_slice(array_values(array_unique($matches[1])), 0, self::MAX_SCRIPTS) as $target) {
                $this->command("make {$target}", self::purposeOf($target), 'Makefile');
            }
        }

        if ($this->files->exists('Dockerfile')) {
            $this->requirement('Docker', null, 'Dockerfile');
            $this->command('docker build .', 'build', 'Dockerfile');
        }

        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'] as $composeFile) {
            if ($this->files->exists($composeFile)) {
                $this->requirement('Docker Compose', null, $composeFile);
                $this->command('docker compose up', 'run', $composeFile);
                break;
            }
        }

        if ($this->files->exists('.env.example')) {
            $this->requirement('fișierul .env', null, '.env.example');
        }
    }

    /**
     * The test setup: test frameworks (PHPUnit, Pest, pytest, ...) and how many test files there are.
     * Without a composer or npm "test" script, PHPUnit's own command is listed.
     */
    private function readTestSetup(): void
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $phpunitFile) {
            if ($this->files->exists($phpunitFile)) {
                $this->testFramework('PHPUnit', $phpunitFile);

                if (! $this->hasCommandWithPurpose('test')) {
                    $this->command('vendor/bin/phpunit', 'test', $phpunitFile);
                }

                break;
            }
        }

        if (isset($this->paths['tests/Pest.php'])) {
            $this->testFramework('Pest', 'tests/Pest.php');
        }

        $pytest = collect($this->testFrameworks)->firstWhere('name', 'pytest');

        if ($pytest !== null && ! $this->hasCommandWithPurpose('test')) {
            $this->command('pytest', 'test', $pytest['source']);
        }

        $testFiles = count(array_filter(array_keys($this->paths), self::isTestFile(...)));

        if ($this->testFrameworks !== [] || $testFiles > 0) {
            $this->tests = ['frameworks' => $this->testFrameworks, 'test_files' => $testFiles];
        }
    }

    /**
     * The license from a LICENSE (or COPYING) file in the root folder: its name when the text is a
     * well-known license, otherwise its first line. Without such a file, a manifest's "license" field.
     */
    private function readLicense(): void
    {
        $licenseFile = collect(array_keys($this->paths))
            ->filter(fn (string|int $path): bool => preg_match('/\A(licen[cs]e|copying)(\.md|\.txt)?\z/i', (string) $path) === 1)
            ->sort()
            ->first();

        if ($licenseFile === null || ($text = $this->files->read((string) $licenseFile)) === null) {
            return;
        }

        $name = self::knownLicense($text) ?? Str::limit(Str::squish((string) collect(preg_split('/\R/', $text) ?: [])
            ->first(fn (string $line): bool => trim($line) !== '')), 80, '…');

        if ($name !== '') {
            $this->license = ['name' => $name, 'source' => (string) $licenseFile];
        }
    }

    /**
     * The files that start the program, by the conventions of frameworks and languages
     * (plus those declared in the manifests, added while reading them).
     */
    private function findEntryPoints(): void
    {
        foreach (self::CONVENTIONAL_ENTRY_POINTS as $path => $kind) {
            if (isset($this->paths[$path])) {
                $this->entryPoint($path, $kind);
            }
        }

        foreach (array_keys($this->paths) as $path) {
            $path = (string) $path;

            if (preg_match('#\Acmd/[^/]+/main\.go\z#', $path) === 1) {
                $this->entryPoint($path, 'un program Go din cmd/');
            } elseif (str_ends_with($path, '__main__.py')) {
                $this->entryPoint($path, 'un pachet Python pornit cu python -m');
            }
        }

        usort($this->entryPoints, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
    }

    /**
     * Add a command once, with what it is for, the file it comes from and, for a script, what it runs.
     */
    private function command(string $command, string $purpose, string $source, ?string $detail = null): void
    {
        if (in_array($command, array_column($this->commands, 'command'), true)) {
            return;
        }

        $this->commands[] = ['command' => $command, 'purpose' => $purpose, 'source' => $source, 'detail' => $detail];
    }

    /**
     * Add a requirement once (the first source with a version wins).
     */
    private function requirement(string $name, ?string $version, string $source): void
    {
        foreach ($this->requirements as $index => $requirement) {
            if ($requirement['name'] === $name) {
                if ($requirement['version'] === null && $version !== null) {
                    $this->requirements[$index] = ['name' => $name, 'version' => $version, 'source' => $source];
                }

                return;
            }
        }

        $this->requirements[] = ['name' => $name, 'version' => $version, 'source' => $source];
    }

    /**
     * Add a test framework once.
     */
    private function testFramework(string $name, string $source): void
    {
        if (! in_array($name, array_column($this->testFrameworks, 'name'), true)) {
            $this->testFrameworks[] = ['name' => $name, 'source' => $source];
        }
    }

    /**
     * Add an entry point once, only for a file the analysis saw.
     */
    private function entryPoint(string $path, string $kind): void
    {
        if (isset($this->paths[$path]) && ! in_array($path, array_column($this->entryPoints, 'path'), true)) {
            $this->entryPoints[] = ['path' => $path, 'kind' => $kind];
        }
    }

    /**
     * Whether a command for this purpose was already found.
     */
    private function hasCommandWithPurpose(string $purpose): bool
    {
        return in_array($purpose, array_column($this->commands, 'purpose'), true);
    }

    /**
     * The license from a manifest's "license" field, when no LICENSE file gave one.
     */
    private function licenseFromManifest(mixed $field, string $source): void
    {
        $name = $this->licenseField($field);

        if ($this->license === null && $name !== null) {
            $this->license = ['name' => $name, 'source' => $source];
        }
    }

    /**
     * The package manager of package.json: the "packageManager" field, else the lock file in the root folder, else npm.
     *
     * @param  array<string, mixed>  $package
     * @return array{name: string, version: string|null, source: string}
     */
    private function packageManager(array $package): array
    {
        $declared = $this->string($package['packageManager'] ?? null);

        if ($declared !== null && preg_match('/\A(npm|pnpm|yarn|bun)@([^+\s]+)/', $declared, $match) === 1) {
            return ['name' => $match[1], 'version' => $match[2], 'source' => 'package.json'];
        }

        foreach (['pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'yarn', 'bun.lock' => 'bun', 'bun.lockb' => 'bun', 'package-lock.json' => 'npm'] as $lockFile => $name) {
            if ($this->files->exists($lockFile)) {
                return ['name' => $name, 'version' => null, 'source' => $lockFile];
            }
        }

        return ['name' => 'npm', 'version' => null, 'source' => 'package.json'];
    }

    /**
     * composer.json scripts as name => what they run, without Composer's own events (pre-..., post-...).
     *
     * @return array<string, string>
     */
    private function composerScripts(mixed $scripts): array
    {
        $result = [];

        foreach (is_array($scripts) ? $scripts : [] as $name => $value) {
            if (! is_string($name) || preg_match('/\A(pre|post)-/', $name) === 1) {
                continue;
            }

            $steps = array_filter(is_array($value) ? $value : [$value], is_string(...));

            if ($steps !== []) {
                $result[$name] = Str::limit(implode(' && ', $steps), 200, '…');
            }
        }

        return array_slice($result, 0, self::MAX_SCRIPTS, true);
    }

    /**
     * package.json scripts as name => what they run, without the ones npm runs by itself
     * (install hooks, and "preX"/"postX" of another script "X").
     *
     * @return array<string, string>
     */
    private function npmScripts(mixed $scripts): array
    {
        $scripts = $this->stringMap($scripts);
        $result = [];

        foreach ($scripts as $name => $command) {
            $hookOf = preg_replace('/\A(pre|post)/', '', $name);

            if (in_array($name, self::NPM_LIFECYCLE_SCRIPTS, true) || ($hookOf !== $name && isset($scripts[$hookOf]))) {
                continue;
            }

            $result[$name] = Str::limit($command, 200, '…');
        }

        return array_slice($result, 0, self::MAX_SCRIPTS, true);
    }

    /**
     * What a script or a Makefile target is for, from its name.
     */
    private static function purposeOf(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'test') => 'test',
            str_starts_with($name, 'build') => 'build',
            in_array($name, ['dev', 'start', 'serve', 'watch', 'preview', 'run'], true) => 'run',
            in_array($name, ['setup', 'install', 'bootstrap'], true) => 'install',
            default => 'script',
        };
    }

    /**
     * Whether a path looks like a test file: in a tests/test/__tests__/spec folder, or named like one.
     */
    private static function isTestFile(string|int $path): bool
    {
        $path = (string) $path;

        return preg_match('#(\A|/)(tests?|__tests__|spec)/#', $path) === 1
            || preg_match('#(_test\.go|\.(test|spec)\.[jt]sx?|Test\.php)\z#', $path) === 1
            || preg_match('#(\A|/)test_[^/]+\.py\z#', $path) === 1;
    }

    /**
     * The name of a well-known license from the beginning of its text, or null.
     */
    private static function knownLicense(string $text): ?string
    {
        $beginning = mb_substr($text, 0, 1000);

        return match (true) {
            preg_match('/\bMIT License\b|Permission is hereby granted, free of charge/i', $beginning) === 1 => 'MIT',
            preg_match('/Apache License,?\s+Version 2\.0/i', $beginning) === 1 => 'Apache-2.0',
            preg_match('/GNU AFFERO GENERAL PUBLIC LICENSE/i', $beginning) === 1 => 'AGPL-3.0',
            preg_match('/GNU LESSER GENERAL PUBLIC LICENSE/i', $beginning) === 1 => 'LGPL',
            preg_match('/GNU GENERAL PUBLIC LICENSE\s+Version 3/i', $beginning) === 1 => 'GPL-3.0',
            preg_match('/GNU GENERAL PUBLIC LICENSE\s+Version 2/i', $beginning) === 1 => 'GPL-2.0',
            preg_match('/Mozilla Public License,?\s+(Version|v\.?)\s*2\.0/i', $beginning) === 1 => 'MPL-2.0',
            preg_match('/This is free and unencumbered software released into the public domain/i', $beginning) === 1 => 'Unlicense',
            preg_match('/\bISC License\b/i', $beginning) === 1 => 'ISC',
            preg_match('/BSD 3-Clause|Redistributions of source code must retain.*?Neither the name/is', $beginning) === 1 => 'BSD-3-Clause',
            preg_match('/BSD 2-Clause|Redistributions of source code must retain/i', $beginning) === 1 => 'BSD-2-Clause',
            default => null,
        };
    }

    /**
     * A JSON file of the clone as an array, or null when it is missing or not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    private function json(string $path): ?array
    {
        $content = $this->files->read($path);
        $data = $content === null ? null : json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * A manifest "license" field (a string or a list of strings) as one string, or null.
     */
    private function licenseField(mixed $field): ?string
    {
        $names = is_array($field) ? array_filter($field, is_string(...)) : (is_string($field) ? [$field] : []);

        return $names === [] ? null : implode(' / ', $names);
    }

    /**
     * Only the entries of a JSON object whose key and value are strings.
     *
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, fn (mixed $item, int|string $key): bool => is_string($key) && is_string($item), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Only the strings of a JSON list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * A non-empty JSON string, trimmed and at most 300 characters long, or null.
     */
    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 300, '…') : null;
    }

    /**
     * The first capture of a regular expression, or null.
     */
    private function firstMatch(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $match) === 1 ? $match[1] : null;
    }

    /**
     * Whether any analyzed path matches.
     *
     * @param  callable(string): bool  $test
     */
    private function anyPath(callable $test): bool
    {
        foreach (array_keys($this->paths) as $path) {
            if ($test((string) $path)) {
                return true;
            }
        }

        return false;
    }
}
