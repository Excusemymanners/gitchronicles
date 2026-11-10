<?php

namespace App\Documentation;

/**
 * Everything a writer may know about one module, built by ContextBuilder::module() from the analysis
 * tables and the clone. Both writers get exactly this, so the AI page and the template page are
 * based on the same facts, and the AI is told to use nothing else.
 *
 * Lists that could be very long are cut (the "...Count" numbers say how much was left out),
 * so the context of a huge module still has a bounded size.
 */
final readonly class ModuleContext
{
    /**
     * @param  string  $repository  "owner/name"
     * @param  string  $path  the module's folder, "/" for the root folder
     * @param  string|null  $folderReadme  the first paragraph of a README in the module's own folder
     * @param  list<array{path: string, language: string|null, lines: int}>  $files  in path order
     * @param  list<array{path: string, kind: string, name: string, start_line: int, end_line: int}>  $symbols  the most important files first, then by line
     * @param  list<array{path: string, weight: int}>  $usesModules  the modules this one imports from (weight = number of imports)
     * @param  list<array{path: string, weight: int}>  $usedByModules  the modules that import from this one
     * @param  list<array{from: string, to: string, external: bool}>  $importsOut  imports of this module's files that point outside it (a file or a library)
     * @param  list<array{from: string, to: string}>  $importsIn  files of other modules that import files of this module
     * @param  list<array{from: string, to: string}>  $internalImports  files of this module that import each other
     * @param  int  $unlistedImportCount  imports left out of the three lists above (each keeps ContextBuilder::MAX_IMPORTS)
     * @param  list<array{path: string, start_line: int, end_line: int, total_lines: int, content: string}>  $excerpts  source code, every line prefixed with its number ("12| ...")
     * @param  list<array{path: string, lines: int, reason: string}>  $omittedFiles  files whose code is not in the excerpts, and why
     * @param  int  $characterBudget  the most characters of code the excerpts may hold
     */
    public function __construct(
        public string $repository,
        public string $path,
        public int $fileCount,
        public int $lineCount,
        public ?string $folderReadme,
        public array $files,
        public int $unlistedFileCount,
        public array $symbols,
        public int $unlistedSymbolCount,
        public array $usesModules,
        public array $usedByModules,
        public array $importsOut,
        public array $importsIn,
        public array $internalImports,
        public int $unlistedImportCount,
        public array $excerpts,
        public array $omittedFiles,
        public int $omittedFileCount,
        public int $characterBudget,
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
     * The sha256 of the context: when it is the same as the one saved with the page, nothing the writer
     * would see has changed, so the page is not written again.
     */
    public function inputHash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
}
