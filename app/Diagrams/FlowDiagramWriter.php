<?php

namespace App\Diagrams;

use App\Ai\AiDisabled;
use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\TextGenerator;
use App\Enums\DiagramType;
use App\Models\CodeSymbol;
use App\Models\Diagram;
use App\Models\Module;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * „Cum funcționează”: Claude explains the 1–3 main flows of the application, each with a small
 * Mermaid diagram and the lines of code it is based on. This is the only AI part of the diagrams
 * and it is best effort: when AI is turned off or Claude fails, the page simply has no flows.
 *
 * 1. context(): the real facts Claude may use — the modules with their main classes, the dependencies,
 *    the important symbols with their lines, and the entry files (routes, controllers, commands, jobs,
 *    main files) with numbered lines, within a fixed budget of characters;
 * 2. the answer is checked by FlowParser (strict JSON, no unsafe Mermaid, real source lines);
 * 3. the valid flows replace the old ones (type "flow", one row each, the key made from the title).
 *
 * The same context gives the same answer, so when it has not changed since the saved flows were
 * written (same input hash), Claude is not asked again: no tokens are spent on an unchanged project.
 */
final class FlowDiagramWriter
{
    /**
     * How many characters of entry-file code the context may hold.
     */
    public const CODE_CHARACTERS = 50000;

    /**
     * How many characters the list of symbols may take.
     */
    public const SYMBOL_CHARACTERS = 12000;

    /**
     * How many modules the context summarizes (the biggest).
     */
    public const SUMMARY_MODULES = 25;

    /**
     * How many module dependencies the context lists (the strongest).
     */
    public const SUMMARY_DEPENDENCIES = 25;

    /**
     * A bigger entry file is not put in the context.
     */
    private const MAX_FILE_BYTES = 200 * 1024;

    /**
     * The entry files, by kind: the first kinds go first in the context. Each pattern is checked
     * against the file's path; files in tests, configuration, documentation, ... are never used.
     */
    private const ENTRY_FILES = [
        'rute' => ['#(^|/)routes/[^/]+\.(php|js|ts|py)$#', '#(^|/)urls\.py$#', '#^bootstrap/app\.php$#'],
        // At most two folders deep, optionally in src/: "main.go", "resources/js/app.tsx", "packages/cli/src/index.ts".
        'fișiere main' => ['#^([^/]+/){0,2}(src/)?(main|__main__|manage|app|cli|server|index)\.(py|go|ts|tsx|js|jsx|mjs)$#', '#(^|/)cmd/.+\.go$#'],
        'controllere' => ['#(^|/)(Controllers|controllers|handlers|Handlers)/#'],
        'comenzi' => ['#(^|/)Console/Commands/#', '#(^|/)commands/#'],
        'joburi' => ['#(^|/)(Jobs|jobs|workers|tasks)/#'],
    ];

    /**
     * The instructions, the same for every repository. The answer format is checked by FlowParser.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Ești un arhitect software care îi explică unui programator nou cum funcționează un proiect.
        Primești modulele proiectului, dependențele dintre ele, simbolurile importante cu liniile lor
        și fișierele de intrare (rute, controllere, comenzi, joburi, fișiere main) cu numerele liniilor.

        Alege cele mai importante 1–3 fluxuri ale aplicației (de exemplu drumul unei cereri a utilizatorului
        până la răspuns, sau un job care rulează în fundal) și descrie fiecare flux.

        Reguli:
        - Folosește doar ce apare în context: fișiere, clase, metode, rute. Nu inventa nimic.
        - Răspunde doar cu un obiect JSON, fără alt text și fără ```, exact în forma:
          {"flows":[{"title":"...","explanation":"...","mermaid":"flowchart TD\n...","sources":[{"path":"...","start_line":1,"end_line":9}]}]}
        - title: scurt, în limba română. explanation: 2–4 propoziții în limba română, pentru cineva nou în proiect.
        - mermaid: o diagramă "flowchart TD" cu 4–10 pași; fiecare nod are eticheta între ghilimele duble, de forma A["Text"].
          Fără HTML, fără click, fără link-uri, fără stiluri (style, classDef), fără %%{init}%%.
        - sources: 1–5 intervale de linii din fișierele din context care arată fluxul; "path" exact ca în context.
        PROMPT;

    /**
     * Create a new writer.
     */
    public function __construct(
        private TextGenerator $generator,
        private CodeOverview $overview,
    ) {}

    /**
     * Ask Claude for the main flows and save them. Returns Claude's answer (for the token counters
     * of the processing run), or null when Claude was not asked.
     */
    public function refresh(Repository $repository): ?AiResult
    {
        [$context, $fileLines] = $this->context($repository);

        if ($fileLines === []) {
            $this->deleteFlows($repository);

            return null;
        }

        $inputHash = hash('sha256', self::SYSTEM_PROMPT."\n".$context);
        $savedFlows = $repository->diagrams()->where('type', DiagramType::Flow)->get();

        if ($savedFlows->isNotEmpty() && $savedFlows->every(fn (Diagram $flow): bool => ($flow->meta['input_hash'] ?? null) === $inputHash)) {
            $repository->diagrams()->where('type', DiagramType::Flow)->update(['source_sha' => $repository->last_sha]);

            return null;
        }

        try {
            $result = $this->generator->generate(self::SYSTEM_PROMPT, $context);
        } catch (AiDisabled) {
            $this->deleteOutdatedFlows($repository);

            return null;
        } catch (AiException $exception) {
            Log::warning('The flows could not be written.', ['repository_id' => $repository->id, 'message' => $exception->getMessage()]);
            $this->deleteOutdatedFlows($repository);

            return null;
        }

        $parsed = FlowParser::parse($result->text, $fileLines);

        if ($parsed['rejected'] !== []) {
            Log::warning('Some flows written by Claude were rejected.', ['repository_id' => $repository->id, 'reasons' => $parsed['rejected']]);
        }

        if ($parsed['flows'] === []) {
            $this->deleteOutdatedFlows($repository);

            return $result;
        }

        DB::transaction(function () use ($repository, $parsed, $result, $inputHash): void {
            $this->deleteFlows($repository);

            foreach ($parsed['flows'] as $flow) {
                $repository->diagrams()->create([
                    'type' => DiagramType::Flow,
                    'key' => $flow['key'],
                    'mermaid' => $flow['mermaid'],
                    'meta' => [
                        'title' => $flow['title'],
                        'explanation' => $flow['explanation'],
                        'sources' => $flow['sources'],
                        'input_hash' => $inputHash,
                        'model' => $result->model,
                        'input_tokens' => $result->inputTokens,
                        'output_tokens' => $result->outputTokens,
                        'cost_usd_equivalent' => $result->costUsdEquivalent,
                    ],
                    'source_sha' => $repository->last_sha,
                ]);
            }
        });

        return $result;
    }

    /**
     * The text Claude receives and, for checking its sources, the number of lines of every file
     * whose lines it saw (path => lines).
     *
     * @return array{string, array<string, int>}
     */
    private function context(Repository $repository): array
    {
        [$modules] = $this->overview->applicationModules($repository);
        $summarized = $modules->sortBy([['line_count', 'desc'], ['path', 'asc']])->take(self::SUMMARY_MODULES)->sortBy('path')->values();

        [$entryFilesText, $shownFiles, $omittedFiles] = $this->entryFiles($repository);
        [$symbolsText, $listedFiles] = $this->symbols($summarized, array_keys($shownFiles));

        $fileLines = [...$listedFiles, ...$shownFiles];

        if ($fileLines === []) {
            return ['', []];
        }

        $sections = [
            // No commit SHA here: the text must change only when the code it shows changes (see the input hash).
            "Repository: {$repository->owner}/{$repository->name}",
            $repository->description ? "Descriere (din README): {$repository->description}" : null,
            "## Module (cele mai mari, cu clasele principale)\n".$this->moduleSummaries($summarized),
            "## Dependențe între module (A → B: fișiere din A importă din B)\n".$this->dependencySummaries($repository, $modules),
            $symbolsText === '' ? null : "## Simboluri importante (fișier: tip nume Lstart-Lend)\n{$symbolsText}",
            $entryFilesText === '' ? null : "## Fișiere de intrare, cu numerele liniilor\n{$entryFilesText}",
            $omittedFiles === [] ? null : 'Fișiere de intrare care nu au mai încăput: '.implode(', ', $omittedFiles),
        ];

        return [implode("\n\n", array_filter($sections)), $fileLines];
    }

    /**
     * The entry files with numbered lines, the most important kinds first, within CODE_CHARACTERS.
     *
     * @return array{string, array<string, int>, list<string>} the text, the shown files (path => lines) and the omitted paths
     */
    private function entryFiles(Repository $repository): array
    {
        $candidates = [];

        foreach (RepositoryFile::query()->whereBelongsTo($repository)->whereIn('language', CodeOverview::CODE_LANGUAGES)->orderBy('path')->get(['path', 'lines']) as $file) {
            $kind = $this->entryKind($file->path);

            if ($kind !== null) {
                $candidates[] = ['path' => $file->path, 'kind' => $kind];
            }
        }

        usort($candidates, fn (array $a, array $b): int => [$a['kind'], $a['path']] <=> [$b['kind'], $b['path']]);

        $blocks = [];
        $shown = [];
        $omitted = [];
        $remaining = self::CODE_CHARACTERS;

        foreach ($candidates as $candidate) {
            $contents = $this->read($repository, $candidate['path']);
            $lines = $contents === null ? [] : preg_split('/\R/', rtrim($contents, "\r\n"));
            $block = "### {$candidate['path']}\n".implode("\n", array_map(fn (string $line, int $index): string => ($index + 1)."| {$line}", $lines, array_keys($lines)));

            if ($contents === null || mb_strlen($block) > $remaining) {
                $omitted[] = $candidate['path'];

                continue;
            }

            $blocks[] = $block;
            $shown[$candidate['path']] = count($lines);
            $remaining -= mb_strlen($block);
        }

        return [implode("\n\n", $blocks), $shown, $omitted];
    }

    /**
     * The kind of an entry file (its position in ENTRY_FILES), or null when it is not one.
     */
    private function entryKind(string $path): ?int
    {
        $folder = dirname($path);

        if ($folder !== '.' && LayerClassifier::exclusionReason($folder) !== null) {
            return null;
        }

        foreach (array_values(self::ENTRY_FILES) as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /**
     * The classes, functions and methods of the summarized modules (except the files already shown
     * whole), with their lines, within SYMBOL_CHARACTERS.
     *
     * @param  Collection<int, Module>  $modules
     * @param  list<string>  $shownPaths
     * @return array{string, array<string, int>} the text and the listed files (path => lines)
     */
    private function symbols(Collection $modules, array $shownPaths): array
    {
        $symbols = CodeSymbol::query()
            ->join('repository_files', 'repository_files.id', '=', 'code_symbols.repository_file_id')
            ->whereIn('repository_files.module_id', $modules->pluck('id'))
            ->whereNotIn('repository_files.path', $shownPaths)
            ->whereIn('code_symbols.kind', ['class', 'interface', 'trait', 'enum', 'function', 'method'])
            ->orderBy('repository_files.path')
            ->orderBy('code_symbols.start_line')
            ->get(['repository_files.path', 'repository_files.lines', 'code_symbols.kind', 'code_symbols.name', 'code_symbols.start_line', 'code_symbols.end_line']);

        $lines = [];
        $listed = [];
        $remaining = self::SYMBOL_CHARACTERS;

        foreach ($symbols->groupBy('path') as $path => $fileSymbols) {
            $line = "{$path}: ".$fileSymbols
                ->map(fn (CodeSymbol $symbol): string => "{$symbol->kind} {$symbol->name} L{$symbol->start_line}-L{$symbol->end_line}")
                ->implode(', ');

            if (mb_strlen($line) > $remaining) {
                break;
            }

            $lines[] = $line;
            $listed[(string) $path] = (int) $fileSymbols->first()->lines;
            $remaining -= mb_strlen($line);
        }

        return [implode("\n", $lines), $listed];
    }

    /**
     * One line per module: path, files, lines and main classes.
     *
     * @param  Collection<int, Module>  $modules
     */
    private function moduleSummaries(Collection $modules): string
    {
        $classNames = $this->overview->mainClassNames($modules, 5);

        return $modules->map(function (Module $module) use ($classNames): string {
            $classes = $classNames[$module->id] ?? [];

            return "- {$module->path}: {$module->file_count} fișiere, {$module->line_count} linii"
                .($classes === [] ? '' : '; '.implode(', ', $classes));
        })->implode("\n") ?: '(niciun modul)';
    }

    /**
     * The strongest dependencies between the application's modules, one per line.
     *
     * @param  Collection<int, Module>  $modules
     */
    private function dependencySummaries(Repository $repository, Collection $modules): string
    {
        $paths = $modules->pluck('path', 'id');

        return collect($this->overview->dependenciesBetween($repository, $modules))
            ->take(self::SUMMARY_DEPENDENCIES)
            ->map(fn (array $dependency): string => "- {$paths[$dependency['from']]} → {$paths[$dependency['to']]}")
            ->implode("\n") ?: '(modulele nu se importă între ele)';
    }

    /**
     * Delete every flow of the repository.
     */
    private function deleteFlows(Repository $repository): void
    {
        $repository->diagrams()->where('type', DiagramType::Flow)->delete();
    }

    /**
     * Without a new answer, keep the flows written for this same commit and delete the older ones:
     * they may describe code that has changed.
     */
    private function deleteOutdatedFlows(Repository $repository): void
    {
        $repository->diagrams()
            ->where('type', DiagramType::Flow)
            ->where(fn ($query) => $query->whereNull('source_sha')->orWhere('source_sha', '!=', $repository->last_sha))
            ->delete();
    }

    /**
     * The contents of a file of the clone, or null when it is missing, a link or too big.
     */
    private function read(Repository $repository, string $path): ?string
    {
        $fullPath = $repository->clonePath().'/'.$path;

        if (! is_file($fullPath) || is_link($fullPath) || filesize($fullPath) > self::MAX_FILE_BYTES) {
            return null;
        }

        return mb_scrub((string) file_get_contents($fullPath), 'UTF-8');
    }
}
