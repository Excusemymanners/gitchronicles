<?php

namespace App\Documentation;

use Illuminate\Support\Collection;

/**
 * Writes the documentation pages without AI, only from the context: the offline mode (AI_DRIVER=none)
 * and the fallback when Claude fails. Every sentence states a fact found by the analysis (sizes,
 * names, line ranges, imports, manifests); it never guesses what the code does. Sections without
 * facts say so (module and overview pages) or are left out (the README).
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
     * The headings of a module page, in order (the AI writer must use the same ones).
     */
    public const MODULE_SECTIONS = ['Rol', 'Componente principale', 'Cum funcționează', 'Dependențe', 'Fișiere'];

    /**
     * The headings of the overview page, in order (the AI writer must use the same ones).
     */
    public const OVERVIEW_SECTIONS = ['Ce face proiectul', 'Tehnologii', 'Arhitectură', 'Module', 'Cum îl rulezi'];

    /**
     * Kinds of symbols left out of „Componente principale”: TypeScript type aliases are mostly the props of
     * a component ("Props"), which would fill the list; the AI writer still gets them in the context.
     */
    private const NOT_COMPONENTS = ['type'];

    /**
     * The Romanian name of each kind of symbol found by the analyzer.
     */
    private const SYMBOL_KINDS = [
        'class' => 'clasă',
        'interface' => 'interfață',
        'trait' => 'trait',
        'enum' => 'enum',
        'function' => 'funcție',
        'method' => 'metodă',
        'type' => 'tip',
        'struct' => 'struct',
    ];

    /**
     * The titles of the command groups, in the order they are shown.
     */
    private const COMMAND_GROUPS = [
        'install' => 'Instalare',
        'run' => 'Pornire',
        'build' => 'Build',
        'test' => 'Teste',
        'script' => 'Alte scripturi',
    ];

    /**
     * The page of one module: structure, components with links to their lines, imports, files.
     */
    public function module(ModuleContext $context, SourceLinks $links): WrittenPage
    {
        $title = $context->path === '/' ? 'Rădăcina proiectului' : 'Modulul '.self::code($context->path);

        $markdown = implode("\n\n", [
            "# {$title}",
            '## Rol',
            $this->moduleRole($context),
            '## Componente principale',
            $this->moduleComponents($context, $links),
            '## Cum funcționează',
            $this->moduleStructure($context),
            '## Dependențe',
            $this->moduleDependencies($context),
            '## Fișiere',
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
            '## Ce face proiectul',
            $this->projectDescription($context, $links),
            '## Tehnologii',
            $this->technologies($context),
            '## Arhitectură',
            $this->architecture($context),
            '## Module',
            $this->moduleTable($context),
            '## Cum îl rulezi',
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
            $sections[] = "## Funcționalități\n\n".self::bulletList($context->readme['features']);
        }

        if ($this->hasTechnologies($context)) {
            $sections[] = "## Tehnologii\n\n".$this->technologies($context);
        }

        if ($context->requirements !== []) {
            $sections[] = "## Cerințe\n\n".self::bulletList(array_map(
                fn (array $requirement): string => self::escape($requirement['name']).($requirement['version'] ? ' '.self::code($requirement['version']) : '').' (din '.self::code($requirement['source']).')',
                $context->requirements,
            ));
        }

        $installCommands = $this->commandsFor($context, ['install']);

        if ($installCommands !== []) {
            $sections[] = "## Instalare\n\n".self::shellBlock([
                'git clone '.$links->cloneUrl(),
                'cd '.$context->name,
                ...$installCommands,
            ]);
        }

        $runCommands = $this->commandsFor($context, ['run', 'build', 'script']);

        if ($runCommands !== []) {
            $sections[] = "## Rulare\n\n".self::shellBlock($runCommands)."\n\n".$this->commandSources($context, ['run', 'build', 'script']);
        }

        $sections[] = "## Structura proiectului\n\n".self::bulletList(array_map(
            fn (array $folder): string => ($folder['path'] === '/' ? 'fișierele din folderul principal' : self::code($folder['path'].'/'))
                .' — '.self::count($folder['files'], 'fișier', 'fișiere').', '.self::count($folder['lines'], 'linie', 'linii')
                .($folder['languages'] !== [] ? ' ('.implode(', ', array_map(self::escape(...), $folder['languages'])).')' : ''),
            $context->topFolders,
        ));

        $sections[] = "## Module\n\n".$this->readmeModules($context, $links);

        if ($context->tests !== null) {
            $sections[] = "## Teste\n\n".$this->readmeTests($context);
        }

        if ($context->license !== null) {
            $sections[] = "## Licență\n\n".self::escape($context->license['name']).' (din '.self::code($context->license['source']).').';
        }

        $sections[] = '---';
        $sections[] = '_README generat automat de GitChronicle din codul de la commitul '.self::code(substr($links->sha, 0, 7)).'._';

        return WrittenPage::fromTemplate(implode("\n\n", $sections)."\n");
    }

    /**
     * „Rol”: size and languages of the module, its own README and who uses it. No guessed purpose.
     */
    private function moduleRole(ModuleContext $context): string
    {
        $languages = collect($context->files)->pluck('language')->filter()->unique()->sort()->values()->all();
        $where = $context->path === '/' ? 'Folderul principal al proiectului' : 'Modulul '.self::code($context->path);

        $paragraphs = [
            "{$where} are ".self::count($context->fileCount, 'fișier', 'fișiere').' și '.self::count($context->lineCount, 'linie', 'linii')
                .($languages !== [] ? ', în '.self::joinWords(array_map(self::escape(...), $languages)) : '').'.',
        ];

        if ($context->folderReadme !== null) {
            $paragraphs[] = 'README-ul din folder spune: „'.self::escape($context->folderReadme).'”';
        }

        $paragraphs[] = $context->usedByModules === []
            ? 'Niciun alt modul al proiectului nu importă fișiere din el.'
            : 'Îl folosesc modulele '.self::joinWords(array_map(fn (array $module): string => self::code($module['path']), array_slice($context->usedByModules, 0, self::MAX_LIST_ITEMS))).'.';

        $paragraphs[] = '_Pagina e scrisă automat din analiza codului: arată structura (fișiere, clase, funcții, importuri), nu ce face codul.'
            .($context->path === '/' ? '' : ' Căile fișierelor din modul sunt scrise fără '.self::code($context->path.'/').'.').'_';

        return implode("\n\n", $paragraphs);
    }

    /**
     * „Componente principale”: the top-level symbols with links to their lines, each with its methods.
     */
    private function moduleComponents(ModuleContext $context, SourceLinks $links): string
    {
        $symbols = collect($context->symbols);
        $topLevel = $symbols
            ->filter(fn (array $symbol): bool => ! in_array($symbol['kind'], self::NOT_COMPONENTS, true) && ! $this->isInsideAnother($symbol, $symbols))
            ->values();

        if ($topLevel->isEmpty()) {
            return 'Analiza nu a găsit clase sau funcții în fișierele acestui modul.';
        }

        $items = $topLevel->take(self::MAX_COMPONENTS)->map(function (array $symbol) use ($symbols, $links, $context): string {
            $members = $symbols
                ->filter(fn (array $other): bool => $other !== $symbol && $other['path'] === $symbol['path']
                    && $other['start_line'] >= $symbol['start_line'] && $other['end_line'] <= $symbol['end_line'])
                ->pluck('name')->unique()->values();

            $line = '**'.self::link(self::code($symbol['name']), $links->lines($symbol['path'], $symbol['start_line'], $symbol['end_line'])).'** — '
                .(self::SYMBOL_KINDS[$symbol['kind']] ?? self::escape($symbol['kind'])).' în '.self::code($this->relativePath($context, $symbol['path']))
                .', '.self::lineRange($symbol['start_line'], $symbol['end_line']);

            if ($members->isNotEmpty()) {
                $line .= '. Conține: '.$members->take(self::MAX_LIST_ITEMS)->map(self::code(...))->implode(', ')
                    .($members->count() > self::MAX_LIST_ITEMS ? ' și încă '.($members->count() - self::MAX_LIST_ITEMS) : '');
            }

            return $line.'.';
        });

        $rest = $topLevel->count() - self::MAX_COMPONENTS + $context->unlistedSymbolCount;

        return self::bulletList($items->all()).($rest > 0 ? "\n\n… și încă ".self::count($rest, 'componentă', 'componente').'.' : '');
    }

    /**
     * A file's path without the module's folder ("components/app-logo.tsx" in "resources/js"), shorter to read.
     */
    private function relativePath(ModuleContext $context, string $path): string
    {
        return $context->path !== '/' && str_starts_with($path, $context->path.'/') ? substr($path, strlen($context->path) + 1) : $path;
    }

    /**
     * „Cum funcționează”, only from the structure: which files use each other, which are used from outside, which libraries.
     */
    private function moduleStructure(ModuleContext $context): string
    {
        $parts = [];

        if ($context->internalImports !== []) {
            $parts[] = "Fișierele modulului se folosesc între ele (importuri):\n\n".self::bulletList(array_map(
                fn (array $import): string => self::code($this->relativePath($context, $import['from'])).' → '.self::code($this->relativePath($context, $import['to'])),
                array_slice($context->internalImports, 0, self::MAX_LIST_ITEMS),
            ));
        }

        $usedFromOutside = collect($context->importsIn)->groupBy('to')
            ->map(fn ($imports, string $path): string => self::code($this->relativePath($context, $path)).' — importat de '.self::count($imports->count(), 'fișier', 'fișiere').' din alte module');

        if ($usedFromOutside->isNotEmpty()) {
            $parts[] = "Din afara modulului se folosesc:\n\n".self::bulletList($usedFromOutside->take(self::MAX_LIST_ITEMS)->values()->all());
        }

        if ($parts === []) {
            $parts[] = 'Analiza nu a găsit importuri între fișierele acestui modul și nici fișiere ale lui folosite din alte module.';
        }

        $parts[] = '_Aici sunt doar legăturile găsite în cod; comportamentul funcțiilor nu se deduce automat._';

        return implode("\n\n", $parts);
    }

    /**
     * „Dependențe”: the modules and libraries it uses, and the modules that use it.
     */
    private function moduleDependencies(ModuleContext $context): string
    {
        $libraries = collect($context->importsOut)->where('external', true)->pluck('to')->unique()->sort()->values();

        $uses = array_map(fn (array $module): string => 'modulul '.self::code($module['path']).' ('.self::count($module['weight'], 'import', 'importuri').')', $context->usesModules);

        if ($libraries->isNotEmpty()) {
            $uses[] = 'biblioteci și pachete din afara proiectului: '.$libraries->take(self::MAX_LIST_ITEMS)->map(self::code(...))->implode(', ')
                .($libraries->count() > self::MAX_LIST_ITEMS ? ' și încă '.($libraries->count() - self::MAX_LIST_ITEMS) : '');
        }

        $usedBy = array_map(fn (array $module): string => 'modulul '.self::code($module['path']).' ('.self::count($module['weight'], 'import', 'importuri').')', $context->usedByModules);

        return "**Folosește:**\n\n".($uses === [] ? 'Nu importă nimic din alte module sau biblioteci.' : self::bulletList($uses))
            ."\n\n**Este folosit de:**\n\n".($usedBy === [] ? 'Niciun alt modul.' : self::bulletList($usedBy));
    }

    /**
     * „Fișiere”: a table of every file (up to the listed limit) with its language and lines.
     */
    private function moduleFiles(ModuleContext $context, SourceLinks $links): string
    {
        $rows = array_map(
            fn (array $file): string => '| '.self::link(self::tableCode($this->relativePath($context, $file['path'])), $links->file($file['path']))
                .' | '.self::cell($file['language'] ?? '—').' | '.self::number($file['lines']).' |',
            $context->files,
        );

        return "| Fișier | Limbaj | Linii |\n|---|---|---:|\n".implode("\n", $rows)
            .($context->unlistedFileCount > 0 ? "\n\n… și încă ".self::count($context->unlistedFileCount, 'fișier', 'fișiere').'.' : '');
    }

    /**
     * „Ce face proiectul”: the README's first paragraph and its feature list, quoted; nothing when there is no README.
     */
    private function projectDescription(OverviewContext $context, SourceLinks $links): string
    {
        if ($context->readme === null) {
            return 'Repository-ul nu are un README în folderul principal, așa că din fișierele lui nu reiese ce face proiectul. Secțiunile de mai jos arată ce conține.';
        }

        $source = self::link(self::code($context->readme['path']), $links->file($context->readme['path']));
        $parts = [];

        $parts[] = $context->readme['description'] !== null
            ? "Din {$source}: „".self::escape($context->readme['description']).'”'
            : "{$source} nu are un paragraf de descriere.";

        if ($context->readme['features'] !== []) {
            $parts[] = "README-ul enumeră:\n\n".self::bulletList($context->readme['features']);
        }

        return implode("\n\n", $parts);
    }

    /**
     * „Tehnologii”: the languages by lines and the main dependencies of each manifest.
     */
    private function technologies(OverviewContext $context): string
    {
        $languages = collect($context->languages)->reject(fn (array $language): bool => $language['name'] === 'Altele');
        $items = [];

        if ($languages->isNotEmpty()) {
            $named = $languages->take(self::MAX_LANGUAGES)->map(fn (array $language): string => self::escape($language['name'])
                .' ('.self::count($language['lines'], 'linie', 'linii').($context->lineCount > 0 ? ', '.self::percent($language['lines'] / $context->lineCount) : '').')');

            $items[] = '**Limbaje:** '.$named->implode(', ').($languages->count() > self::MAX_LANGUAGES ? ' și încă '.($languages->count() - self::MAX_LANGUAGES) : '');
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
            $line .= $main ? self::packages($main) : 'fără dependențe declarate';
            $line .= $development ? '; pentru dezvoltare: '.self::packages($development) : '';

            $items[] = $line;
        }

        return $items === [] ? 'Analiza nu a recunoscut limbajul fișierelor și nu a găsit fișiere de pachete.' : self::bulletList($items);
    }

    /**
     * Whether the analysis recognized a language or found a manifest (the README leaves „Tehnologii” out otherwise).
     */
    private function hasTechnologies(OverviewContext $context): bool
    {
        return $context->manifests !== [] || collect($context->languages)->contains(fn (array $language): bool => $language['name'] !== 'Altele');
    }

    /**
     * „Arhitectură”: sizes, the top folders, the entry points and the strongest dependencies between modules.
     */
    private function architecture(OverviewContext $context): string
    {
        $parts = ['Analiza a citit '.self::count($context->fileCount, 'fișier', 'fișiere').' ('.self::count($context->lineCount, 'linie', 'linii').') și '
            .self::count(count($context->modules), 'modul', 'module').'. Un modul este un folder; „/” înseamnă fișierele din folderul principal.'];

        $parts[] = "**Folderele principale:**\n\n".self::bulletList(array_map(
            fn (array $folder): string => ($folder['path'] === '/' ? 'folderul principal' : self::code($folder['path'].'/'))
                .' — '.self::count($folder['files'], 'fișier', 'fișiere').', '.self::count($folder['lines'], 'linie', 'linii')
                .($folder['languages'] !== [] ? ' ('.implode(', ', array_map(self::escape(...), $folder['languages'])).')' : ''),
            $context->topFolders,
        ));

        if ($context->entryPoints !== []) {
            $parts[] = "**Puncte de intrare** (după numele fișierelor și după fișierele de pachete):\n\n".self::bulletList(array_map(
                fn (array $entryPoint): string => self::code($entryPoint['path']).' — '.self::escape($entryPoint['kind']),
                $context->entryPoints,
            ));
        }

        if ($context->dependencies !== []) {
            $shown = count($context->dependencies);
            $parts[] = '**Cele mai puternice dependențe între module**'.($shown < $context->dependencyCount ? " ({$shown} din {$context->dependencyCount})" : '')
                .":\n\n".self::bulletList(array_map(
                    fn (array $dependency): string => self::code($dependency['from']).' → '.self::code($dependency['to']).' ('.self::count($dependency['weight'], 'import', 'importuri').')',
                    $context->dependencies,
                ));
        } else {
            $parts[] = 'Analiza nu a găsit importuri între module.';
        }

        return implode("\n\n", $parts);
    }

    /**
     * „Module”: every module, with a link to its page or the reason it has none.
     */
    private function moduleTable(OverviewContext $context): string
    {
        if ($context->modules === []) {
            return 'Analiza nu a găsit niciun modul.';
        }

        $rows = array_map(
            fn (array $module): string => '| '.($module['page'] !== null ? self::link(self::tableCode($module['path']), $module['page']) : self::tableCode($module['path']))
                .' | '.self::number($module['files']).' | '.self::number($module['lines']).' | '
                .($module['page'] !== null ? 'pagină proprie' : 'fără pagină: '.self::cell($module['reason'] ?? '')).' |',
            $context->modules,
        );

        return "Modulele cu pagină proprie au legătură spre ea.\n\n| Modul | Fișiere | Linii | Documentație |\n|---|---:|---:|---|\n".implode("\n", $rows);
    }

    /**
     * „Cum îl rulezi”: what must be installed and the commands found in the manifests, grouped by purpose.
     */
    private function howToRun(OverviewContext $context): string
    {
        if ($context->commands === []) {
            return 'Fișierele proiectului nu arată cum se instalează sau se pornește: în folderul principal nu există fișiere de pachete sau scripturi (composer.json, package.json, Makefile, ...).';
        }

        $parts = ['Comenzile de mai jos sunt cele găsite în fișierele proiectului, cu fișierul din care vin.'];

        if ($context->requirements !== []) {
            $parts[] = '**Ai nevoie de:** '.implode(', ', array_map(
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
                fn (array $command): string => self::code($command['command']).' — din '.self::code($command['source'])
                    .($command['detail'] !== null ? ', rulează '.self::code($command['detail']) : ''),
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

        $languages = collect($context->languages)->reject(fn (array $language): bool => $language['name'] === 'Altele')->take(3)->pluck('name');

        return 'Repository-ul '.self::code($context->repository).' are '.self::count($context->fileCount, 'fișier', 'fișiere')
            .' și '.self::count($context->lineCount, 'linie', 'linii').($languages->isNotEmpty() ? ', scrise mai ales în '.self::joinWords($languages->map(self::escape(...))->all()) : '').'.';
    }

    /**
     * The README's module list: the biggest modules with code (those that have a documentation page),
     * linked to their folder on GitHub.
     */
    private function readmeModules(OverviewContext $context, SourceLinks $links): string
    {
        $modules = collect($context->modules)->whereNotNull('page')->sortBy([['lines', 'desc'], ['path', 'asc']]);

        if ($modules->isEmpty()) {
            return 'Analiza nu a găsit module cu cod într-un limbaj recunoscut.';
        }

        $shown = $modules->take(self::MAX_README_MODULES)->sortBy('path')->map(
            fn (array $module): string => self::link(self::code($module['path']), $links->folder($module['path']))
                .' — '.self::count($module['files'], 'fișier', 'fișiere').', '.self::count($module['lines'], 'linie', 'linii'),
        );

        return self::bulletList($shown->values()->all())
            .($modules->count() > self::MAX_README_MODULES ? "\n\n… și încă ".self::count($modules->count() - self::MAX_README_MODULES, 'modul mai mic', 'module mai mici').'.' : '');
    }

    /**
     * The README's test section: the frameworks, the number of test files and the test commands.
     */
    private function readmeTests(OverviewContext $context): string
    {
        $items = array_map(fn (array $framework): string => self::escape($framework['name']).' (din '.self::code($framework['source']).')', $context->tests['frameworks']);

        if ($context->tests['test_files'] > 0) {
            $items[] = self::count($context->tests['test_files'], 'fișier de test', 'fișiere de test');
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

        return 'Comenzile vin din '.self::joinWords($sources->values()->all()).'.';
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
     * "liniile 10–42" (or "linia 7" for one line).
     */
    private static function lineRange(int $start, int $end): string
    {
        return $start === $end ? "linia {$start}" : "liniile {$start}–{$end}";
    }

    /**
     * A number with the Romanian thousands separator: 12.345.
     */
    private static function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /**
     * A share between 0 and 1 as a Romanian percentage: "45,2 %".
     */
    private static function percent(float $share): string
    {
        return number_format($share * 100, 1, ',', '.').' %';
    }

    /**
     * A number with the right Romanian word: "1 fișier", "5 fișiere", "20 de fișiere", "101 fișiere".
     */
    private static function count(int $value, string $singular, string $plural): string
    {
        if ($value === 1) {
            return "1 {$singular}";
        }

        $lastTwoDigits = $value % 100;
        $needsDe = $value !== 0 && ($lastTwoDigits === 0 || $lastTwoDigits >= 20);

        return self::number($value).($needsDe ? ' de ' : ' ').$plural;
    }

    /**
     * Words joined the Romanian way: "a", "a și b", "a, b și c".
     *
     * @param  list<string>  $words
     */
    private static function joinWords(array $words): string
    {
        if (count($words) <= 1) {
            return $words[0] ?? '';
        }

        return implode(', ', array_slice($words, 0, -1)).' și '.end($words);
    }
}
