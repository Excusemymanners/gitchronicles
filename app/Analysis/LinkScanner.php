<?php

namespace App\Analysis;

/**
 * Finds the connections of a repository's code that its import lines do not show, so the module graph
 * and the diagrams can connect what really works together (Faza 7). Each link is one place in the code
 * (file, line and the matched text) with a kind:
 *
 * - inside the code: "reference" (a class of the same namespace, used without a `use` line), "import"
 *   (a package of the same repository imported by its name), "route", "dispatch", "event", "render",
 *   "binding", "schedule" (Laravel, see PhpLinkScanner), "loads" (an app entry that loads every page with
 *   `import.meta.glob`), "runs" (a script of the repository started by another program);
 * - outside the code: "runs" a program (git, ffmpeg, ...), "calls" a service (a URL host or an SDK),
 *   "stores" in a database, cache or file storage, "queues" work.
 *
 * PHP is read with its tokenizer (PhpLinkScanner); JavaScript/TypeScript, Python, shell, Rust and Go with
 * simple patterns, one line at a time, skipping comment lines. Any other language contributes nothing
 * rather than guesses. The scan is bounded: files over MAX_FILE_BYTES are skipped, it stops reading after
 * MAX_TOTAL_BYTES and keeps at most MAX_LINKS_PER_FILE links per file.
 */
final class LinkScanner
{
    /**
     * Every kind of link, with the verb a diagram writes on its arrow.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        'import' => 'uses',
        'reference' => 'uses',
        'route' => 'routes to',
        'dispatch' => 'dispatches',
        'event' => 'fires',
        'render' => 'renders',
        'binding' => 'binds',
        'schedule' => 'schedules',
        'loads' => 'loads',
        'runs' => 'runs',
        'calls' => 'calls',
        'stores' => 'reads and writes',
        'queues' => 'queues work on',
        'links' => 'links to',
    ];

    /**
     * A bigger file is not read.
     */
    public const MAX_FILE_BYTES = 512 * 1024;

    /**
     * The scan stops reading files after this many bytes.
     */
    public const MAX_TOTAL_BYTES = 48 * 1024 * 1024;

    /**
     * At most this many links are kept for one file.
     */
    public const MAX_LINKS_PER_FILE = 200;

    /**
     * At most this many files one glob links to.
     */
    private const MAX_GLOB_FILES = 300;

    /**
     * The languages read, by the analyzer's name.
     */
    private const LANGUAGES = ['PHP', 'JavaScript', 'TypeScript', 'Python', 'Shell', 'Rust', 'Go'];

    /**
     * What shows that a file sends HTTP requests, by language: only then are its URL literals services it calls.
     *
     * @var array<string, string>
     */
    private const HTTP_CLIENTS = [
        'JavaScript' => '/\b(fetch|axios|got|ky|ofetch|\$fetch|superagent|undici|XMLHttpRequest|https?\.(request|get))\b|octokit/i',
        'Python' => '/^\s*(import|from)\s+(requests|httpx|urllib|aiohttp|http\.client|urllib3)\b/m',
        'Rust' => '/\b(reqwest|hyper|ureq|surf|isahc)::/',
        'Go' => '/"net\/http"/',
        'Shell' => '/(^|[\s;|&(`])(curl|wget)\s/m',
    ];

    /**
     * Calls that start another program, by language; the first group is the command (or the first word of it).
     *
     * @var array<string, list<string>>
     */
    private const PROCESS_PATTERNS = [
        'JavaScript' => [
            '/\b(?:spawn|spawnSync|execFile|execFileSync|exec|execSync|execa|execaSync|fork)\s*\(\s*([\'"`])([^\'"`]+)\1/',
            '/\b(?:spawn|spawnSync|execa)\s*\(\s*\[\s*([\'"`])([^\'"`]+)\1/',
            '/\bnew\s+Worker\s*\(\s*(?:new\s+URL\s*\(\s*)?([\'"`])([^\'"`]+)\1/',
        ],
        'Python' => [
            '/\bsubprocess\.(?:run|Popen|call|check_call|check_output)\s*\(\s*\[?\s*f?([\'"])([^\'"]+)\1/',
            '/\bos\.(?:system|popen|execvp?)\s*\(\s*f?([\'"])([^\'"]+)\1/',
            '/\bcreate_subprocess_(?:exec|shell)\s*\(\s*f?([\'"])([^\'"]+)\1/',
        ],
        'Rust' => ['/\bCommand::new\s*\(\s*(")([^"]+)"/'],
        'Go' => ['/\bexec\.Command(?:Context)?\s*\(\s*(?:\w+\s*,\s*)?(")([^"]+)"/'],
    ];

    /**
     * Read the code of a repository and return its links.
     *
     * @param  string  $root  the clone folder
     * @param  list<array{path: string, language: string|null, imports?: list<array{raw: string, resolved_path: string|null, external: bool}>}>  $files  the analyzed files
     * @param  list<string>  $otherPaths  the other tracked files (for the package.json of the workspace packages)
     * @return list<array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}>
     */
    public function scan(string $root, array $files, array $otherPaths = []): array
    {
        $paths = array_column($files, 'path');
        $targets = new LinkTargets($paths, $this->packageJsons($root, [...$paths, ...$otherPaths]));
        $sources = $this->sources($root, $files);
        $php = new PhpLinkScanner;
        $links = [];

        foreach ($sources as $path => [$language, $fullPath]) {
            if ($language === 'PHP') {
                $php->index($path, (string) file_get_contents($fullPath));
            }
        }

        // One file in memory at a time: a big repository has tens of megabytes of code.
        foreach ($files as $file) {
            [$language, $fullPath] = $sources[$file['path']] ?? [$file['language'], null];
            $source = $fullPath === null ? null : (string) file_get_contents($fullPath);
            $found = match (true) {
                $source === null => [],
                $language === 'PHP' => $php->scan($file['path'], $source, $targets),
                default => $this->scanLines($file['path'], $language === 'TypeScript' ? 'JavaScript' : $language, $source, $targets),
            };

            array_push($found, ...$this->fromImports($file, (string) $language, $targets, $source));
            array_push($links, ...array_slice($this->unique($found), 0, self::MAX_LINKS_PER_FILE));
        }

        return $this->unique([...$links, ...$php->deferred]);
    }

    /**
     * A short excerpt of a line of code for the evidence table.
     */
    public static function excerpt(string $line): string
    {
        return mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', mb_scrub($line, 'UTF-8'))), 0, 160, '…');
    }

    /**
     * Whether a path is a test or a fixture: in a tests folder, or named *.test.*, *.spec.*, *_test.go, test_*.py.
     */
    public static function isTest(string $path): bool
    {
        return preg_match('~(^|/)(tests?|__tests__|__mocks__|e2e|fixtures?|spec)/|\.(test|spec)\.\w+$|_test\.(go|py)$|(^|/)test_[^/]+\.py$~i', $path) === 1;
    }

    /**
     * The URL hosts in a piece of text ("https://api.github.com/repos/x" → "api.github.com").
     *
     * @return list<string>
     */
    public static function urlHosts(string $text): array
    {
        if (! str_contains($text, '://')) {
            return [];
        }

        preg_match_all('~\bhttps?://([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+)~i', $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * The links of a file in one of the languages read line by line.
     *
     * @return list<array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}>
     */
    private function scanLines(string $path, string $language, string $source, LinkTargets $targets): array
    {
        $links = [];
        $sendsRequests = isset(self::HTTP_CLIENTS[$language]) && preg_match(self::HTTP_CLIENTS[$language], $source) === 1;
        $startsPrograms = $language === 'Shell' || array_filter(self::PROCESS_PATTERNS[$language] ?? [], fn (string $pattern): bool => preg_match($pattern, $source) === 1) !== [];
        $inDocstring = false;

        foreach (explode("\n", $source) as $index => $line) {
            $number = $index + 1;
            $trimmed = ltrim($line);

            if ($language === 'Python' && substr_count($line, '"""') + substr_count($line, "'''") === 1) {
                $inDocstring = ! $inDocstring;

                continue;
            }

            if ($inDocstring || $trimmed === '' || $this->isCommentLine($trimmed, $language)) {
                continue;
            }

            foreach ($this->commandsOn($line, $language, $startsPrograms) as $command) {
                $links[] = $this->commandLink($path, $number, $line, $command, $targets);
            }

            if ($sendsRequests) {
                foreach (self::urlHosts($this->quotedText($line, $language)) as $host) {
                    if (($service = LinkCatalog::service($host)) !== null) {
                        $links[] = $this->link($path, $number, 'calls', null, "service:{$service}", $line);
                    }
                }
            }

            if ($language === 'JavaScript' && preg_match('/\bimport\.meta\.glob(?:Eager)?\s*(?:<[^>]*>)?\s*\(\s*\[?\s*([\'"`])([^\'"`]+)\1|\brequire\.context\s*\(\s*([\'"`])([^\'"`]+)\3/', $line, $match) === 1) {
                foreach ($this->globFiles($path, $match[2] !== '' ? $match[2] : $match[4], $targets) as $file) {
                    $links[] = $this->link($path, $number, 'loads', $file, null, $line);
                }
            }
        }

        return array_values(array_filter($links));
    }

    /**
     * The commands a line starts: from the process calls of the language, or, in a shell script, the words
     * in command position (line start, after a pipe, `;`, `&&`, `$(`, `then`, `do`, ...).
     *
     * @return list<string>
     */
    private function commandsOn(string $line, string $language, bool $startsPrograms): array
    {
        if (! $startsPrograms) {
            return [];
        }

        $commands = [];

        foreach (self::PROCESS_PATTERNS[$language] ?? [] as $pattern) {
            if (preg_match_all($pattern, $line, $matches) > 0) {
                array_push($commands, ...$matches[2]);
            }
        }

        if ($language === 'Python' && preg_match_all('/\[\s*([\'"])([\w.\/-]+)\1\s*,/', $line, $matches) > 0) {
            array_push($commands, ...array_filter($matches[2], fn (string $word): bool => isset(LinkCatalog::TOOLS[$word])));
        }

        if ($language === 'Shell') {
            $code = (string) preg_replace('/([\'"]).*?\1/', '""', $line);

            if (preg_match_all('/(?:^\s*|[|;&]\s*|\$\(\s*|`\s*|\b(?:then|do|else|exec|command|sudo|time|xargs)\s+)([\w.\/$-]+(?:\s+[\w.\/$"{}-]+)?)/', $code, $matches) > 0) {
                array_push($commands, ...$matches[1]);
            }
        }

        return $commands;
    }

    /**
     * The link of one command: a known program, or a script of the repository (directly, or after an
     * interpreter: "python3 analyzer/analyze.py"), or null when it is neither.
     *
     * @return array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}|null
     */
    private function commandLink(string $path, int $line, string $text, string $command, LinkTargets $targets): ?array
    {
        $words = preg_split('/\s+/', trim(str_replace(['"', "'", '${', '}', '$'], ' ', $command))) ?: [];
        $first = $words[0] ?? '';

        if (($tool = LinkCatalog::tool($first)) !== null) {
            return $this->link($path, $line, 'runs', null, "tool:{$tool}", $text);
        }

        $scriptWord = LinkCatalog::isInterpreter($first) ? ($words[1] ?? '') : $first;
        $script = $targets->script($scriptWord) ?? $targets->script($this->relativeTo($path, $scriptWord));

        return $script !== null && $script !== $path ? $this->link($path, $line, 'runs', $script, null, $text) : null;
    }

    /**
     * The links a file's imports give: an SDK of a service ("calls"), a library that keeps data ("stores" /
     * "queues"), or a package of the same repository imported by its name ("import").
     *
     * @param  array{path: string, language: string|null, imports?: list<array{raw: string, resolved_path: string|null, external: bool}>}  $file
     * @return list<array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}>
     */
    private function fromImports(array $file, string $language, LinkTargets $targets, ?string $source): array
    {
        $links = [];
        $lines = $source === null ? [] : explode("\n", $source);

        foreach ($file['imports'] ?? [] as $import) {
            if ($import['resolved_path'] !== null || $language === 'PHP') {
                if ($language === 'Python' && $import['resolved_path'] !== null && str_ends_with($file['path'], 'urls.py') && preg_match('#(^|/)views(\.py|/)#', $import['resolved_path']) === 1) {
                    $links[] = $this->link($file['path'], $this->lineOf($lines, $import['raw']), 'route', $import['resolved_path'], null, $lines[$this->lineOf($lines, $import['raw']) - 1] ?? $import['raw']);
                }

                continue;
            }

            $raw = $import['raw'];
            $line = $this->lineOf($lines, $raw);
            $text = $lines[$line - 1] ?? $raw;

            if (($service = LinkCatalog::sdk($raw, $language)) !== null) {
                $links[] = $this->link($file['path'], $line, 'calls', null, "service:{$service}", $text);
            } elseif (($store = LinkCatalog::storeLibrary(str_replace('::', '/', $raw))) !== null) {
                $links[] = $this->link($file['path'], $line, $store === 'queue' ? 'queues' : 'stores', null, "store:{$store}", $text);
            } elseif (in_array($language, ['JavaScript', 'TypeScript'], true) && $targets->hasPackages() && ($entry = $targets->package($raw)) !== null && $entry !== $file['path']) {
                $links[] = $this->link($file['path'], $line, 'import', $entry, null, $text);
            }
        }

        return $links;
    }

    /**
     * The files a glob pattern of a file matches ("./pages/**\/*.tsx" from resources/js/app.tsx).
     *
     * @return list<string>
     */
    private function globFiles(string $path, string $pattern, LinkTargets $targets): array
    {
        $folder = (string) preg_replace('#/?[^/]*[*{].*$#', '', $pattern);
        $extensions = preg_match('/\.\{([\w,]+)\}$/', $pattern, $match) === 1 ? explode(',', $match[1]) : (preg_match('/\*\.(\w+)$/', $pattern, $match) === 1 ? [$match[1]] : []);

        return array_values(array_filter(
            $targets->filesUnder($this->relativeTo($path, $folder === '' ? '.' : $folder), $extensions, self::MAX_GLOB_FILES),
            fn (string $file): bool => $file !== $path,
        ));
    }

    /**
     * A path written relative to a file ("./pages", "../lib/x.js"), as a path from the repository root.
     */
    private function relativeTo(string $file, string $relative): string
    {
        if (! str_starts_with($relative, '.')) {
            return $relative;
        }

        $parts = dirname($file) === '.' ? [] : explode('/', dirname($file));

        foreach (explode('/', $relative) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * The text inside the quotes of a line (so a URL in a trailing comment is not taken).
     */
    private function quotedText(string $line, string $language): string
    {
        preg_match_all($language === 'Rust' || $language === 'Go' ? '/"([^"]*)"|`([^`]*)`/' : '/"([^"]*)"|\'([^\']*)\'|`([^`]*)`/', $line, $matches);

        return implode(' ', array_merge(...array_slice($matches, 1)));
    }

    /**
     * Whether a (left-trimmed) line is a comment in the language.
     */
    private function isCommentLine(string $line, string $language): bool
    {
        return match ($language) {
            'Python', 'Shell' => str_starts_with($line, '#'),
            default => str_starts_with($line, '//') || str_starts_with($line, '/*') || str_starts_with($line, '*'),
        };
    }

    /**
     * The first line that contains the text, or 1.
     *
     * @param  list<string>  $lines
     */
    private function lineOf(array $lines, string $text): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $text)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * One link row.
     *
     * @return array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}
     */
    private function link(string $from, int $line, string $kind, ?string $to, ?string $target, string $text): array
    {
        return ['from' => $from, 'line' => $line, 'kind' => $kind, 'to' => $to, 'target' => $target, 'excerpt' => self::excerpt($text)];
    }

    /**
     * The links without repetitions (same file, line, kind and target).
     *
     * @param  list<array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}|null>  $links
     * @return list<array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}>
     */
    private function unique(array $links): array
    {
        $unique = [];

        foreach (array_filter($links) as $link) {
            $unique["{$link['from']}|{$link['line']}|{$link['kind']}|{$link['to']}|{$link['target']}"] ??= $link;
        }

        return array_values($unique);
    }

    /**
     * The files in a language we read, within the size limits: path => [language, full path on disk].
     *
     * @param  list<array{path: string, language: string|null}>  $files
     * @return array<string, array{string, string}>
     */
    private function sources(string $root, array $files): array
    {
        $sources = [];
        $total = 0;

        foreach ($files as $file) {
            // Tests are not the running program: what they start or call is no evidence of how it works.
            if (! in_array($file['language'], self::LANGUAGES, true) || self::isTest($file['path'])) {
                continue;
            }

            $full = "{$root}/{$file['path']}";

            if (is_link($full) || ! is_file($full) || filesize($full) > self::MAX_FILE_BYTES) {
                continue;
            }

            $total += (int) filesize($full);

            if ($total > self::MAX_TOTAL_BYTES) {
                break;
            }

            $sources[$file['path']] = [$file['language'], $full];
        }

        return $sources;
    }

    /**
     * The package.json files of the repository (outside node_modules), path => contents, at most 200.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function packageJsons(string $root, array $paths): array
    {
        $manifests = [];

        foreach ($paths as $path) {
            if (basename($path) === 'package.json' && ! str_contains($path, 'node_modules/') && is_file("{$root}/{$path}") && ! is_link("{$root}/{$path}") && filesize("{$root}/{$path}") < 256 * 1024) {
                $manifests[$path] = (string) file_get_contents("{$root}/{$path}");

                if (count($manifests) === 200) {
                    break;
                }
            }
        }

        return $manifests;
    }
}
