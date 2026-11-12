<?php

namespace App\Documentation;

use Illuminate\Support\Collection;

/**
 * Writes the documentation pages without AI, only from the context: the offline mode (AI_DRIVER=none)
 * and the fallback when Claude fails. Every sentence states a fact found by the analysis (sizes,
 * names, line ranges, imports, manifests); it never guesses what the code does. Sections without
 * facts say so (module and overview pages) or are left out (the README).
 *
 * The pages are written in English, the content language (App\Support\ContentLanguage). A second
 * content language would get its own sentences here, chosen by ContentLanguage::code().
 */
final class TemplateDocumentationWriter implements DocumentationWriter
{
    /**
     * At most this many components (classes, functions, ...) are listed on a module page.
     */
    public const MAX_COMPONENTS = 25;

    /**
     * At most this many items are listed in the shorter lists (imports, dependencies, libraries).
     */
    public const MAX_LIST_ITEMS = 15;

    /**
     * At most this many languages are named one by one; the rest are counted together.
     */
    public const MAX_LANGUAGES = 6;

    /**
     * At most this many modules are listed in the generated README (the biggest; the overview lists all).
     */
    public const MAX_README_MODULES = 30;

    /**
     * The title of the page of the root folder's module ("/"), here, in the AI prompt and in the menu.
     */
    public const ROOT_MODULE_TITLE = 'Project root';

    /**
     * The headings of a module page, in order (the AI writer must use the same ones).
     */
    public const MODULE_SECTIONS = ['Role', 'Main components', 'How it works', 'Dependencies', 'Files'];

    /**
     * The headings of the overview page, in order (the AI writer must use the same ones).
     */
    public const OVERVIEW_SECTIONS = ['What the project does', 'Technologies', 'Architecture', 'Modules', 'How to run it'];

    /**
     * Kinds of symbols left out of "Main components": TypeScript type aliases are mostly the props of
     * a component ("Props"), which would fill the list; the AI writer still gets them in the context.
     */
    private const NOT_COMPONENTS = ['type'];

    /**
     * The name of each kind of symbol found by the analyzer.
     */
    private const SYMBOL_KINDS = [
        'class' => 'class',
        'interface' => 'interface',
        'trait' => 'trait',
        'enum' => 'enum',
        'function' => 'function',
        'method' => 'method',
        'type' => 'type',
        'struct' => 'struct',
    ];

    /**
     * The titles of the command groups, in the order they are shown.
     */
    private const COMMAND_GROUPS = [
        'install' => 'Install',
        'run' => 'Run',
        'build' => 'Build',
        'test' => 'Test',
        'script' => 'Other scripts',
    ];

    /**
     * The page of one module: structure, components with links to their lines, imports, files.
     */
    public function module(ModuleContext $context, SourceLinks $links): WrittenPage
    {
        $title = $context->path === '/' ? self::ROOT_MODULE_TITLE : 'Module '.self::code($context->path);

        $markdown = implode("\n\n", [
            "# {$title}",
            '## Role',
            $this->moduleRole($context),
            '## Main components',
            $this->moduleComponents($context, $links),
            '## How it works',
            $this->moduleStructure($context),
            '## Dependencies',
            $this->moduleDependencies($context),
            '## Files',
            $this->moduleFiles($context, $links),
        ]);

        return WrittenPage::fromTemplate($markdown."\n");
    }

    /**
     * The overview page: what the README says, the technologies, the architecture, every module, the commands.
     */
    public function overview(OverviewContext $context, SourceLinks $links): WrittenPage
    {
        $markdown = implode("\n\n", [
            '# '.self::escape($context->repository),
            '## What the project does',
            $this->projectDescription($context, $links),
            '## Technologies',
            $this->technologies($context),
            '## Architecture',
            $this->architecture($context),
            '## Modules',
            $this->moduleTable($context),
            '## How to run it',
            $this->howToRun($context),
        ]);

        return WrittenPage::fromTemplate($markdown."\n");
    }

    /**
     * The generated README: only the sections the repository gives evidence for.
     */
    public function readme(OverviewContext $context, SourceLinks $links): WrittenPage
    {
        $sections = ['# '.self::escape($context->name), $this->readmeDescription($context)];

        if ($context->readme !== null && $context->readme['features'] !== []) {
            $sections[] = "## Features\n\n".self::bulletList($context->readme['features']);
        }

        if ($this->hasTechnologies($context)) {
            $sections[] = "## Technologies\n\n".$this->technologies($context);
        }

        if ($context->requirements !== []) {
            $sections[] = "## Requirements\n\n".self::bulletList(array_map(
                fn (array $requirement): string => self::escape($requirement['name']).($requirement['version'] ? ' '.self::code($requirement['version']) : '').' (from '.self::code($requirement['source']).')',
                $context->requirements,
            ));
        }

        $installCommands = $this->commandsFor($context, ['install']);

        if ($installCommands !== []) {
            $sections[] = "## Installation\n\n".self::shellBlock([
                'git clone '.$links->cloneUrl(),
                'cd '.$context->name,
                ...$installCommands,
            ]);
        }

        $runCommands = $this->commandsFor($context, ['run', 'build', 'script']);

        if ($runCommands !== []) {
            $sections[] = "## Running\n\n".self::shellBlock($runCommands)."\n\n".$this->commandSources($context, ['run', 'build', 'script']);
        }

        $sections[] = "## Project structure\n\n".self::bulletList(array_map(
            fn (array $folder): string => ($folder['path'] === '/' ? 'files in the root folder' : self::code($folder['path'].'/'))
                .' — '.self::count($folder['files'], 'file', 'files').', '.self::count($folder['lines'], 'line', 'lines')
                .($folder['languages'] !== [] ? ' ('.implode(', ', array_map(self::escape(...), $folder['languages'])).')' : ''),
            $context->topFolders,
        ));

        $sections[] = "## Modules\n\n".$this->readmeModules($context, $links);

        if ($context->tests !== null) {
            $sections[] = "## Tests\n\n".$this->readmeTests($context);
        }

        if ($context->license !== null) {
            $sections[] = "## License\n\n".self::escape($context->license['name']).' (from '.self::code($context->license['source']).').';
        }

        $sections[] = '---';
        $sections[] = '_README generated by GitChronicle from the code at commit '.self::code(substr($links->sha, 0, 7)).'._';

        return WrittenPage::fromTemplate(implode("\n\n", $sections)."\n");
    }

    /**
     * "Role": size and languages of the module, its own README and who uses it. No guessed purpose.
     */
    private function moduleRole(ModuleContext $context): string
    {
        $languages = collect($context->files)->pluck('language')->filter()->unique()->sort()->values()->all();
        $where = $context->path === '/' ? 'The root folder of the project' : 'The module '.self::code($context->path);

        $paragraphs = [
            "{$where} has ".self::count($context->fileCount, 'file', 'files').' with '.self::count($context->lineCount, 'line', 'lines')
                .($languages !== [] ? ' of '.self::joinWords(array_map(self::escape(...), $languages)) : '').'.',
        ];

        if ($context->folderReadme !== null) {
            $paragraphs[] = 'The README in this folder says: “'.self::escape($context->folderReadme).'”';
        }

        $users = array_map(fn (array $module): string => self::code($module['path']), array_slice($context->usedByModules, 0, self::MAX_LIST_ITEMS));

        $paragraphs[] = match (count($users)) {
            0 => 'No other module of the project imports files from it.',
            1 => "The module {$users[0]} imports files from it.",
            default => 'The modules '.self::joinWords($users).' import files from it.',
        };

        $paragraphs[] = '_This page is generated from the code analysis: it shows the structure (files, classes, functions, imports), not what the code does.'
            .($context->path === '/' ? '' : ' File paths are relative to '.self::code($context->path.'/').'.').'_';

        return implode("\n\n", $paragraphs);
    }

    /**
     * "Main components": the top-level symbols with links to their lines, each with its methods.
     */
    private function moduleComponents(ModuleContext $context, SourceLinks $links): string
    {
        $symbols = collect($context->symbols);
        $topLevel = $symbols
            ->filter(fn (array $symbol): bool => ! in_array($symbol['kind'], self::NOT_COMPONENTS, true) && ! $this->isInsideAnother($symbol, $symbols))
            ->values();

        if ($topLevel->isEmpty()) {
            return 'The analysis found no classes or functions in the files of this module.';
        }

        $items = $topLevel->take(self::MAX_COMPONENTS)->map(function (array $symbol) use ($symbols, $links, $context): string {
            $members = $symbols
                ->filter(fn (array $other): bool => $other !== $symbol && $other['path'] === $symbol['path']
                    && $other['start_line'] >= $symbol['start_line'] && $other['end_line'] <= $symbol['end_line'])
                ->pluck('name')->unique()->values();

            $line = '**'.self::link(self::code($symbol['name']), $links->lines($symbol['path'], $symbol['start_line'], $symbol['end_line'])).'** — '
                .(self::SYMBOL_KINDS[$symbol['kind']] ?? self::escape($symbol['kind'])).' in '.self::code($this->relativePath($context, $symbol['path']))
                .', '.self::lineRange($symbol['start_line'], $symbol['end_line']);

            if ($members->isNotEmpty()) {
                $line .= '. Members: '.self::joinWords($members->take(self::MAX_LIST_ITEMS)->map(self::code(...))->all(), $members->count() - self::MAX_LIST_ITEMS);
            }

            return $line.'.';
        });

        $rest = $topLevel->count() - self::MAX_COMPONENTS + $context->unlistedSymbolCount;

        return self::bulletList($items->all()).($rest > 0 ? "\n\n… and ".self::count($rest, 'more component', 'more components').'.' : '');
    }

    /**
     * A file's path without the module's folder ("components/app-logo.tsx" in "resources/js"), shorter to read.
     */
    private function relativePath(ModuleContext $context, string $path): string
    {
        return $context->path !== '/' && str_starts_with($path, $context->path.'/') ? substr($path, strlen($context->path) + 1) : $path;
    }

    /**
     * "How it works", only from the structure: which files use each other, which are used from outside, which libraries.
     */
    private function moduleStructure(ModuleContext $context): string
    {
        $parts = [];

        if ($context->internalImports !== []) {
            $parts[] = "Files of the module that import each other:\n\n".self::bulletList(array_map(
                fn (array $import): string => self::code($this->relativePath($context, $import['from'])).' → '.self::code($this->relativePath($context, $import['to'])),
                array_slice($context->internalImports, 0, self::MAX_LIST_ITEMS),
            ));
        }

        $usedFromOutside = collect($context->importsIn)->groupBy('to')
            ->map(fn ($imports, string $path): string => self::code($this->relativePath($context, $path)).' — imported by '.self::count($imports->count(), 'file', 'files').' of other modules');

        if ($usedFromOutside->isNotEmpty()) {
            $parts[] = "Files used from outside the module:\n\n".self::bulletList($usedFromOutside->take(self::MAX_LIST_ITEMS)->values()->all());
        }

        if ($parts === []) {
            $parts[] = 'The analysis found no imports between the files of this module, and no file of it is used by other modules.';
        }

        $parts[] = '_This section lists only the links found in the code; the behavior of the functions is not inferred._';

        return implode("\n\n", $parts);
    }

    /**
     * "Dependencies": the modules and libraries it uses, and the modules that use it.
     */
    private function moduleDependencies(ModuleContext $context): string
    {
        $libraries = collect($context->importsOut)->where('external', true)->pluck('to')->unique()->sort()->values();

        $uses = array_map(fn (array $module): string => 'module '.self::code($module['path']).' ('.self::count($module['weight'], 'import', 'imports').')', $context->usesModules);

        if ($libraries->isNotEmpty()) {
            $uses[] = 'external libraries and packages: '.self::joinWords($libraries->take(self::MAX_LIST_ITEMS)->map(self::code(...))->all(), $libraries->count() - self::MAX_LIST_ITEMS);
        }

        $usedBy = array_map(fn (array $module): string => 'module '.self::code($module['path']).' ('.self::count($module['weight'], 'import', 'imports').')', $context->usedByModules);

        return "**Uses:**\n\n".($uses === [] ? 'Nothing from other modules or external libraries.' : self::bulletList($uses))
            ."\n\n**Used by:**\n\n".($usedBy === [] ? 'No other module.' : self::bulletList($usedBy));
    }

    /**
     * "Files": a table of every file (up to the listed limit) with its language and lines.
     */
    private function moduleFiles(ModuleContext $context, SourceLinks $links): string
    {
        $rows = array_map(
            fn (array $file): string => '| '.self::link(self::tableCode($this->relativePath($context, $file['path'])), $links->file($file['path']))
                .' | '.self::cell($file['language'] ?? '—').' | '.self::number($file['lines']).' |',
            $context->files,
        );

        return "| File | Language | Lines |\n|---|---|---:|\n".implode("\n", $rows)
            .($context->unlistedFileCount > 0 ? "\n\n… and ".self::count($context->unlistedFileCount, 'more file', 'more files').'.' : '');
    }

    /**
     * "What the project does": the README's first paragraph and its feature list, quoted; nothing when there is no README.
     */
    private function projectDescription(OverviewContext $context, SourceLinks $links): string
    {
        if ($context->readme === null) {
            return 'The repository has no README in its root folder, so its files do not say what the project does. The sections below show what it contains.';
        }

        $source = self::link(self::code($context->readme['path']), $links->file($context->readme['path']));
        $parts = [];

        $parts[] = $context->readme['description'] !== null
            ? "From {$source}: “".self::escape($context->readme['description']).'”'
            : "{$source} has no description paragraph.";

        if ($context->readme['features'] !== []) {
            $parts[] = "The README lists:\n\n".self::bulletList($context->readme['features']);
        }

        return implode("\n\n", $parts);
    }

    /**
     * "Technologies": the languages by lines and the main dependencies of each manifest.
     */
    private function technologies(OverviewContext $context): string
    {
        $languages = collect($context->languages)->reject(fn (array $language): bool => $language['name'] === ContextBuilder::OTHER_LANGUAGE);
        $items = [];

        if ($languages->isNotEmpty()) {
            $named = $languages->take(self::MAX_LANGUAGES)->map(fn (array $language): string => self::escape($language['name'])
                .' ('.self::count($language['lines'], 'line', 'lines').($context->lineCount > 0 ? ', '.self::percent($language['lines'] / $context->lineCount) : '').')');

            $items[] = '**Languages:** '.self::joinWords($named->all(), $languages->count() - self::MAX_LANGUAGES);
        }

        foreach ($context->manifests as $manifest) {
            $summary = $manifest['summary'];
            $main = $summary['require'] ?? $summary['dependencies'] ?? null;
            $development = $summary['require_dev'] ?? $summary['dev_dependencies'] ?? null;

            if ($main === null && $development === null) {
                $items[] = '**'.self::escape($manifest['kind']).'** ('.self::code($manifest['path']).')';

                continue;
            }

            $line = '**'.self::code($manifest['path']).':** ';
            $line .= $main ? self::packages($main) : 'no declared dependencies';
            $line .= $development ? '; for development: '.self::packages($development) : '';

            $items[] = $line;
        }

        return $items === [] ? 'The analysis did not recognize the language of the files and found no package manifests.' : self::bulletList($items);
    }

    /**
     * Whether the analysis recognized a language or found a manifest (the README leaves "Technologies" out otherwise).
     */
    private function hasTechnologies(OverviewContext $context): bool
    {
        return $context->manifests !== [] || collect($context->languages)->contains(fn (array $language): bool => $language['name'] !== ContextBuilder::OTHER_LANGUAGE);
    }

    /**
     * "Architecture": sizes, the top folders, the entry points and the strongest dependencies between modules.
     */
    private function architecture(OverviewContext $context): string
    {
        $parts = ['The analysis read '.self::count($context->fileCount, 'file', 'files').' ('.self::count($context->lineCount, 'line', 'lines').') in '
            .self::count(count($context->modules), 'module', 'modules').'. A module is a folder; “/” stands for the files of the root folder.'];

        $parts[] = "**Top folders:**\n\n".self::bulletList(array_map(
            fn (array $folder): string => ($folder['path'] === '/' ? 'root folder' : self::code($folder['path'].'/'))
                .' — '.self::count($folder['files'], 'file', 'files').', '.self::count($folder['lines'], 'line', 'lines')
                .($folder['languages'] !== [] ? ' ('.implode(', ', array_map(self::escape(...), $folder['languages'])).')' : ''),
            $context->topFolders,
        ));

        if ($context->entryPoints !== []) {
            $parts[] = "**Entry points** (by file name and by the package manifests):\n\n".self::bulletList(array_map(
                fn (array $entryPoint): string => self::code($entryPoint['path']).' — '.self::escape($entryPoint['kind']),
                $context->entryPoints,
            ));
        }

        if ($context->dependencies !== []) {
            $shown = count($context->dependencies);
            $parts[] = '**Strongest dependencies between modules**'.($shown < $context->dependencyCount ? " ({$shown} of {$context->dependencyCount})" : '')
                .":\n\n".self::bulletList(array_map(
                    fn (array $dependency): string => self::code($dependency['from']).' → '.self::code($dependency['to']).' ('.self::count($dependency['weight'], 'import', 'imports').')',
                    $context->dependencies,
                ));
        } else {
            $parts[] = 'The analysis found no imports between modules.';
        }

        return implode("\n\n", $parts);
    }

    /**
     * "Modules": every module, with a link to its page or the reason it has none.
     */
    private function moduleTable(OverviewContext $context): string
    {
        if ($context->modules === []) {
            return 'The analysis found no modules.';
        }

        $rows = array_map(
            fn (array $module): string => '| '.($module['page'] !== null ? self::link(self::tableCode($module['path']), $module['page']) : self::tableCode($module['path']))
                .' | '.self::number($module['files']).' | '.self::number($module['lines']).' | '
                .($module['page'] !== null ? 'own page' : 'no page: '.self::cell($module['reason'] ?? '')).' |',
            $context->modules,
        );

        return "Modules with their own page link to it.\n\n| Module | Files | Lines | Documentation |\n|---|---:|---:|---|\n".implode("\n", $rows);
    }

    /**
     * "How to run it": what must be installed and the commands found in the manifests, grouped by purpose.
     */
    private function howToRun(OverviewContext $context): string
    {
        if ($context->commands === []) {
            return 'The project files do not show how to install or start it: the root folder has no package manifests or scripts (composer.json, package.json, Makefile, ...).';
        }

        $parts = ['These are the commands found in the project files, each with the file it comes from.'];

        if ($context->requirements !== []) {
            $parts[] = '**Requirements:** '.implode(', ', array_map(
                fn (array $requirement): string => self::escape($requirement['name']).($requirement['version'] ? ' '.self::code($requirement['version']) : ''),
                $context->requirements,
            )).'.';
        }

        foreach (self::COMMAND_GROUPS as $purpose => $title) {
            $commands = array_filter($context->commands, fn (array $command): bool => $command['purpose'] === $purpose);

            if ($commands === []) {
                continue;
            }

            $parts[] = "**{$title}:**\n\n".self::bulletList(array_map(
                fn (array $command): string => self::code($command['command']).' — from '.self::code($command['source'])
                    .($command['detail'] !== null ? ', runs '.self::code($command['detail']) : ''),
                $commands,
            ));
        }

        return implode("\n\n", $parts);
    }

    /**
     * The README's opening paragraph: the README's own description, or the facts of the analysis.
     */
    private function readmeDescription(OverviewContext $context): string
    {
        if ($context->readme !== null && $context->readme['description'] !== null) {
            return self::escape($context->readme['description']);
        }

        $languages = collect($context->languages)->reject(fn (array $language): bool => $language['name'] === ContextBuilder::OTHER_LANGUAGE)->take(3)->pluck('name');

        return 'The repository '.self::code($context->repository).' has '.self::count($context->fileCount, 'file', 'files')
            .' with '.self::count($context->lineCount, 'line', 'lines').($languages->isNotEmpty() ? ', written mostly in '.self::joinWords($languages->map(self::escape(...))->all()) : '').'.';
    }

    /**
     * The README's module list: the biggest modules with code (those that have a documentation page),
     * linked to their folder on GitHub.
     */
    private function readmeModules(OverviewContext $context, SourceLinks $links): string
    {
        $modules = collect($context->modules)->whereNotNull('page')->sortBy([['lines', 'desc'], ['path', 'asc']]);

        if ($modules->isEmpty()) {
            return 'The analysis found no modules with code in a recognized language.';
        }

        $shown = $modules->take(self::MAX_README_MODULES)->sortBy('path')->map(
            fn (array $module): string => self::link(self::code($module['path']), $links->folder($module['path']))
                .' — '.self::count($module['files'], 'file', 'files').', '.self::count($module['lines'], 'line', 'lines'),
        );

        return self::bulletList($shown->values()->all())
            .($modules->count() > self::MAX_README_MODULES ? "\n\n… and ".self::count($modules->count() - self::MAX_README_MODULES, 'smaller module', 'smaller modules').'.' : '');
    }

    /**
     * The README's test section: the frameworks, the number of test files and the test commands.
     */
    private function readmeTests(OverviewContext $context): string
    {
        $items = array_map(fn (array $framework): string => self::escape($framework['name']).' (from '.self::code($framework['source']).')', $context->tests['frameworks']);

        if ($context->tests['test_files'] > 0) {
            $items[] = self::count($context->tests['test_files'], 'test file', 'test files');
        }

        $commands = $this->commandsFor($context, ['test']);

        return self::bulletList($items).($commands !== [] ? "\n\n".self::shellBlock($commands) : '');
    }

    /**
     * The commands with one of the given purposes.
     *
     * @param  list<string>  $purposes
     * @return list<string>
     */
    private function commandsFor(OverviewContext $context, array $purposes): array
    {
        return array_values(array_map(
            fn (array $command): string => $command['command'],
            array_filter($context->commands, fn (array $command): bool => in_array($command['purpose'], $purposes, true)),
        ));
    }

    /**
     * One line saying which files the commands of these purposes come from.
     *
     * @param  list<string>  $purposes
     */
    private function commandSources(OverviewContext $context, array $purposes): string
    {
        $sources = collect($context->commands)->whereIn('purpose', $purposes)->pluck('source')->unique()->sort()->map(self::code(...));

        return 'The commands come from '.self::joinWords($sources->values()->all()).'.';
    }

    /**
     * Whether a symbol lies inside another symbol of the same file (a method inside its class).
     *
     * @param  array{path: string, start_line: int, end_line: int}  $symbol
     * @param  Collection<int, array{path: string, start_line: int, end_line: int}>  $symbols
     */
    private function isInsideAnother(array $symbol, Collection $symbols): bool
    {
        return $symbols->contains(fn (array $other): bool => $other !== $symbol && $other['path'] === $symbol['path']
            && $other['start_line'] <= $symbol['start_line'] && $other['end_line'] >= $symbol['end_line']
            && ($other['start_line'] < $symbol['start_line'] || $other['end_line'] > $symbol['end_line']));
    }

    /**
     * A list of package => version as "`name` version, ...".
     *
     * @param  array<string, string>  $packages
     */
    private static function packages(array $packages): string
    {
        return implode(', ', array_map(
            fn (string $name, string $version): string => self::code($name).' '.self::escape($version),
            array_keys($packages),
            $packages,
        ));
    }

    /**
     * A Markdown bullet list.
     *
     * @param  list<string>  $items
     */
    private static function bulletList(array $items): string
    {
        return implode("\n", array_map(fn (string $item): string => "- {$item}", $items));
    }

    /**
     * A fenced shell code block.
     *
     * @param  list<string>  $commands
     */
    private static function shellBlock(array $commands): string
    {
        return "```bash\n".implode("\n", $commands)."\n```";
    }

    /**
     * A Markdown link.
     */
    private static function link(string $text, string $url): string
    {
        return "[{$text}](".str_replace([' ', ')'], ['%20', '%29'], $url).')';
    }

    /**
     * Inline code. A text with backticks gets a longer fence, as Markdown requires.
     */
    private static function code(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $fence = str_contains($text, '`') ? '``' : '`';
        $padding = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence.$padding.$text.$padding.$fence;
    }

    /**
     * Plain text with the characters Markdown would interpret escaped (so a name like __init__ stays as it is).
     */
    private static function escape(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]<>#|~])/', '\\\\$1', str_replace(["\r", "\n"], ' ', $text));
    }

    /**
     * Inline code inside a table cell, where "|" must be escaped even in code (GitHub-flavored Markdown).
     */
    private static function tableCode(string $text): string
    {
        return str_replace('|', '\|', self::code($text));
    }

    /**
     * Plain text for a table cell.
     */
    private static function cell(string $text): string
    {
        return self::escape($text);
    }

    /**
     * "lines 10–42" (or "line 7" for one line).
     */
    private static function lineRange(int $start, int $end): string
    {
        return $start === $end ? "line {$start}" : "lines {$start}–{$end}";
    }

    /**
     * A number with the thousands separator: 12,345.
     */
    private static function number(int $value): string
    {
        return number_format($value);
    }

    /**
     * A share between 0 and 1 as a percentage: "45.2%".
     */
    private static function percent(float $share): string
    {
        return number_format($share * 100, 1).'%';
    }

    /**
     * A number with its noun: "1 file", "5 files", "1,200 files".
     */
    private static function count(int $value, string $singular, string $plural): string
    {
        return $value === 1 ? "1 {$singular}" : self::number($value).' '.$plural;
    }

    /**
     * Words joined as a list: "a", "a and b", "a, b, and c". With $more above 0 the list ends with
     * "and N more", for the items that were left out.
     *
     * @param  list<string>  $words
     */
    private static function joinWords(array $words, int $more = 0): string
    {
        if ($more > 0) {
            $words[] = self::number($more).' more';
        }

        if (count($words) <= 2) {
            return implode(' and ', $words);
        }

        return implode(', ', array_slice($words, 0, -1)).', and '.end($words);
    }
}
