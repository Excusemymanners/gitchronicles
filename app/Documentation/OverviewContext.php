<?php

namespace App\Documentation;

/**
 * Everything a writer may know about the whole project, built by ContextBuilder::overview(): the overview
 * page and the generated README are both written from it. Every fact comes from the analysis tables
 * or from files of the clone (README, LICENSE, the package manifests); nothing is guessed.
 */
final readonly class OverviewContext
{
    /**
     * @param  string  $repository  "owner/name"
     * @param  string  $name  the repository's name
     * @param  array{path: string, description: string|null, features: list<string>, excerpt: string, truncated: bool}|null  $readme  the README of the root folder
     * @param  list<array{name: string, files: int, lines: int}>  $languages  most lines first; "Altele" = no recognized language
     * @param  list<array{path: string, kind: string, summary: array<string, mixed>}>  $manifests  the package manifests of the root folder
     * @param  list<array{path: string, files: int, lines: int, languages: list<string>}>  $topFolders  "/" = the files of the root folder
     * @param  list<array{path: string, files: int, lines: int, page: string|null, reason: string|null}>  $modules  page = the path of its documentation page; reason = why it has none
     * @param  list<array{from: string, to: string, weight: int}>  $dependencies  the strongest dependencies between modules
     * @param  list<array{path: string, kind: string}>  $entryPoints  files that start the program, by name or by a manifest
     * @param  list<array{command: string, purpose: string, source: string, detail: string|null}>  $commands  only commands found in the manifests (purpose: install, run, build, test, script)
     * @param  list<array{name: string, version: string|null, source: string}>  $requirements  what must be installed, from the manifests
     * @param  array{frameworks: list<array{name: string, source: string}>, test_files: int}|null  $tests  the test setup, null when there is none
     * @param  array{name: string, source: string}|null  $license
     */
    public function __construct(
        public string $repository,
        public string $name,
        public ?array $readme,
        public int $fileCount,
        public int $lineCount,
        public array $languages,
        public array $manifests,
        public array $topFolders,
        public array $modules,
        public array $dependencies,
        public int $dependencyCount,
        public array $entryPoints,
        public array $commands,
        public array $requirements,
        public ?array $tests,
        public ?array $license,
    ) {}

    /**
     * The context as an array (the order of the keys is the order of the properties).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * The sha256 of the context (see ModuleContext::inputHash()).
     */
    public function inputHash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
}
