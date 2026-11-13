<?php

namespace App\Diagrams;

use App\Models\Repository;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Picks the line of code that really does what an arrow of a diagram says.
 *
 * An arrow between two parts is backed by many links of the code; citing the first one found looks random.
 * This class collects the links that start in a module of the source part and end in a module of the target
 * part (or at the program or service outside the code), leaves out guard lines, and ranks the rest, best
 * first, by:
 *
 *  1. not a test file;
 *  2. not the driver the configuration did not select (two classes that do the same job);
 *  3. the kind of link that matches the verb ("saves" -> a store written to, "asks" -> a dispatch or a call);
 *  4. the code path of the earlier steps (a class an earlier step already reached);
 *  5. the module the part is named after ("Documentation writer" -> app/Documentation);
 *  6. a fixed order (kind, path, line), so the same facts give the same answer.
 *
 * rank() is pure (arrays in, arrays out) so it is tested on fixtures; between() and outside() read the
 * candidates from the table code_links, and summary() writes the one line shown under the step.
 */
final class StepEvidence
{
    /**
     * The classes that do the same job for each value of the "ai_driver" setting. Only the selected one is cited.
     */
    public const DRIVER_CLASSES = ['claude-code' => 'ClaudeCodeGenerator', 'claude' => 'ClaudeApiGenerator'];

    /**
     * The driver cited when AI is switched off in this environment: the one the application documents as its default.
     */
    private const DEFAULT_DRIVER = 'claude-code';

    private const MAX_CANDIDATES = 3000;

    /**
     * A line that only decides whether something happens (a condition, a check, an early return) does not do it.
     */
    private const GUARD = '/^\s*(use|import|namespace|from)\s|^\s*(\}\s*)?(else\s*)?((if|elseif|unless|while|assert|abort_if|abort_unless|throw_if|throw_unless)\b|return\s*!|return\s+(null|false|true)\b)|Schema::has|\binstanceof\b|\b(is_null|is_file|is_dir|file_exists|isset|empty|blank|filled)\s*\(|->(isEmpty|isNotEmpty|exists|doesntExist|missing)\s*\(\)\s*[)?:]/i';

    /**
     * A statement that writes to a store, and one that reads from it.
     */
    private const WRITES = '/(->|::)(save|create|update|insert|upsert|updateOrCreate|firstOrCreate|updateOrInsert|forceFill|fill|put|write|touch|createMany|saveMany|increment|decrement|transaction)\s*\(|file_put_contents|Storage::[a-z]*[Pp]ut|\bINSERT\b|\bUPDATE\b/';

    /**
     * A declaration (a signature, a class line) says what exists, not that something happens.
     */
    private const DECLARATION = '/^\s*((public|private|protected|static|final|abstract|readonly)\s+)*(function|class|interface|trait|enum)\b/';

    private const READS = '/(->|::)(where|find|findOrFail|first|firstOrFail|get|exists|count|pluck|value|all|query|select|read|latest|sole)\s*\(|file_get_contents|Storage::(get|read)/';

    /**
     * Create the collector of candidates.
     */
    public function __construct(private ArchitectureFacts $imports) {}

    /**
     * The candidates for an arrow between two parts (kind, label and modules or evidence ids each): links
     * between their modules, or the lines where the code part uses the thing outside the code.
     *
     * @param  array{kind: string, modules: list<string>}  $from
     * @param  array{kind: string, modules: list<string>}  $to
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    public function forParts(Repository $repository, array $from, array $to, array $facts): array
    {
        $ids = fn (array $part): array => array_filter(array_combine($part['modules'], array_map(fn (string $path): ?int => $facts['modules'][$path]['id'] ?? null, $part['modules'])), fn (?int $id): bool => $id !== null);
        [$fromIsCode, $toIsCode] = [in_array($from['kind'], SystemMapValidator::CODE_KINDS, true), in_array($to['kind'], SystemMapValidator::CODE_KINDS, true)];

        return match (true) {
            $fromIsCode && $toIsCode => $this->between($repository, $ids($from), $ids($to)),
            $fromIsCode => $this->outside($repository, $ids($from), $to['modules']),
            $toIsCode => $this->outside($repository, $ids($to), $from['modules']),
            default => [],
        };
    }

    /**
     * What the target of an arrow is for the ranking: "data" (a store), "code" or "outside".
     *
     * @param  array{kind: string}  $part
     */
    public static function typeOf(array $part): string
    {
        return $part['kind'] === 'data' ? 'data' : (in_array($part['kind'], SystemMapValidator::CODE_KINDS, true) ? 'code' : 'outside');
    }

    /**
     * The citation of every request of a flow again, from the facts: the best backed line of the code for each
     * message between parts of code (or code and the outside), a response citing the line of its request; a
     * message with no line that does it keeps no citation. Messages with a person keep theirs.
     *
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function citeFlow(Repository $repository, array $flow, array $facts): array
    {
        $parts = array_column($flow['participants'], null, 'id');
        $reached = [];
        $open = [];

        foreach ($flow['messages'] as &$message) {
            [$from, $to] = [$parts[$message['from']], $parts[$message['to']]];

            if ($from['kind'] === 'person' || $to['kind'] === 'person') {
                continue;
            }

            if ($message['type'] === 'response') {
                $request = $open["{$message['to']}>{$message['from']}"] ?? null;
                $message['code'] = $request === null ? $message['code'] : $request['code'];
                $message['does'] = $request === null ? $message['does'] : $request['does'];

                continue;
            }

            $ranked = self::rank($this->forParts($repository, $from, $to, $facts), [
                'verb' => $message['text'],
                'from_label' => $from['label'],
                'to_label' => $to['label'],
                'to_type' => self::typeOf($to),
                'reached' => $reached,
                'driver' => self::selectedDriver(),
                'hint' => $message['code']['path'] ?? null,
            ]);

            $best = $ranked === [] ? null : $this->locate($repository, $ranked[0]);
            $message['code'] = $best === null || $best['line'] === null ? null : ['path' => $best['from'], 'start_line' => $best['line'], 'end_line' => $best['line']];
            $message['does'] = $best === null ? null : $this->summary($repository, $best);

            if ($best !== null) {
                $reached[$best['from']] = true;
                $reached[$best['to'] ?? $best['from']] = true;
            }

            $open["{$message['from']}>{$message['to']}"] = $message;
        }

        return $flow;
    }

    /**
     * The links of the code that start in one of $from's modules and end in one of $to's modules (test files
     * included, ranking puts them last), and the imports between them as the last resort.
     *
     * @param  array<string, int>  $from  module path => id
     * @param  array<string, int>  $to  module path => id
     * @return list<array{from: string, to: string|null, line: int|null, kind: string, excerpt: string|null, from_module: string, to_module: string|null, target: string|null}>
     */
    public function between(Repository $repository, array $from, array $to): array
    {
        if ($from === [] || $to === []) {
            return [];
        }

        $fromPaths = array_flip($from);
        $toPaths = array_flip($to);

        $rows = DB::table('code_links')
            ->join('repository_files as source', 'source.id', '=', 'code_links.repository_file_id')
            ->join('repository_files as target', 'target.id', '=', 'code_links.target_file_id')
            ->where('code_links.repository_id', $repository->id)
            ->whereIn('source.module_id', array_values($from))
            ->whereIn('target.module_id', array_values($to))
            ->orderBy('source.path')
            ->orderBy('code_links.line')
            ->limit(self::MAX_CANDIDATES)
            ->select('source.path as from_path', 'target.path as to_path', 'source.module_id as from_module', 'target.module_id as to_module', 'code_links.line', 'code_links.kind', 'code_links.excerpt')
            ->get();

        $candidates = $rows->map(fn (object $row): array => [
            'from' => $row->from_path, 'to' => $row->to_path, 'line' => (int) $row->line, 'kind' => $row->kind, 'excerpt' => $row->excerpt,
            'from_module' => $fromPaths[$row->from_module], 'to_module' => $toPaths[$row->to_module], 'target' => null,
        ])->all();

        foreach ($this->imports->examples($repository, $this->pairs($from, $to)) as $pair => $imports) {
            [$fromId, $toId] = array_map('intval', explode(':', $pair));

            foreach ($imports as $import) {
                $candidates[] = ['from' => $import['from'], 'to' => $import['to'], 'line' => null, 'kind' => 'import', 'excerpt' => null, 'from_module' => $fromPaths[$fromId], 'to_module' => $toPaths[$toId], 'target' => null];
            }
        }

        return $candidates;
    }

    /**
     * The lines where one of $from's modules uses one of the things outside the code ($targets: evidence ids).
     *
     * @param  array<string, int>  $from  module path => id
     * @param  list<string>  $targets
     * @return list<array{from: string, to: string|null, line: int|null, kind: string, excerpt: string|null, from_module: string, to_module: string|null, target: string|null}>
     */
    public function outside(Repository $repository, array $from, array $targets): array
    {
        if ($from === [] || $targets === []) {
            return [];
        }

        $fromPaths = array_flip($from);
        $rows = $this->outsideRows($repository, $from, fn ($query) => $query->whereIn('code_links.target', $targets));

        // The selected driver does the work of its sibling: when the evidence names one driver class, the other counts too.
        if ($rows->contains(fn (object $row): bool => in_array(pathinfo($row->path, PATHINFO_FILENAME), self::DRIVER_CLASSES, true))) {
            $rows = $rows->merge($this->outsideRows($repository, $from, fn ($query) => $query->whereNotNull('code_links.target')->where('repository_files.path', 'like', '%/'.self::selectedDriver().'.php')))->unique(fn (object $row): string => $row->path.':'.$row->line);
        }

        return $rows->map(fn (object $row): array => [
            'from' => $row->path, 'to' => null, 'line' => (int) $row->line, 'kind' => $row->kind, 'excerpt' => $row->excerpt,
            'from_module' => $fromPaths[$row->module_id], 'to_module' => null, 'target' => $row->target,
        ])->values()->all();
    }

    /**
     * The lines of the given modules' files that use something outside the code, narrowed by $where.
     *
     * @param  array<string, int>  $from
     * @return Collection<int, object>
     */
    private function outsideRows(Repository $repository, array $from, Closure $where): Collection
    {
        return DB::table('code_links')
            ->join('repository_files', 'repository_files.id', '=', 'code_links.repository_file_id')
            ->where('code_links.repository_id', $repository->id)
            ->where($where)
            ->whereIn('repository_files.module_id', array_values($from))
            ->orderBy('repository_files.path')
            ->orderBy('code_links.line')
            ->limit(self::MAX_CANDIDATES)
            ->select('repository_files.path', 'repository_files.module_id', 'code_links.target', 'code_links.line', 'code_links.kind', 'code_links.excerpt')
            ->get();
    }

    /**
     * The candidates in the order to cite them, best first, without the guard lines. $context:
     * verb (the arrow's text), from_label and to_label (the parts' names), to_type ("data" for a store, "code",
     * or the outside kind), reached (paths the earlier steps cited, as keys), hint (a file an earlier answer cited; a tie-break only), driver (the class cited for the
     * configured driver, or null).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array{verb: string, from_label: string, to_label: string, to_type: string, reached: array<string, mixed>, driver: string|null, hint?: string|null}  $context
     * @return list<array<string, mixed>>
     */
    public static function rank(array $candidates, array $context): array
    {
        $wanted = self::wantedKinds($context['verb'], $context['to_type']);
        $scored = [];

        foreach ($candidates as $candidate) {
            $excerpt = (string) ($candidate['excerpt'] ?? '');

            if (self::isNotEvidence($candidate['from']) || self::isGuard($excerpt) || (self::isDeclaration($excerpt) && $candidate['kind'] !== 'queues') || ! self::isEligible($candidate, $wanted)) {
                continue;
            }

            $scored[] = [self::score($candidate, $wanted, $context), $candidate];
        }

        usort($scored, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $pair): array => $pair[1], $scored);
    }

    /**
     * Whether a candidate can back the verb at all: "saves" and "reads" on a store need a statement of that
     * kind on a store, not a dispatch, a path lookup or a read for a write.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array{kinds: list<string>, meaning: string|null}  $wanted
     */
    private static function isEligible(array $candidate, array $wanted): bool
    {
        if ($wanted['meaning'] === null || $candidate['to_module'] !== null) {
            return true;
        }

        return self::verbTier($candidate, $wanted) === 0;
    }

    /**
     * Whether a line of code only checks a condition instead of doing something.
     */
    public static function isGuard(string $excerpt): bool
    {
        return preg_match(self::GUARD, $excerpt) === 1;
    }

    /**
     * Whether a file is no evidence of how the program works: tests, helper scripts, seeders, factories, benchmarks.
     */
    private static function isNotEvidence(string $path): bool
    {
        return preg_match('~(^|/)(tests?|__\w+__|e2e|fixtures?|spec|scripts?)/|\.(test|spec)\.|(Benchmark|Seeder|Factory|Fake)\w*\.php$~i', $path) === 1;
    }

    /**
     * How many of the page and the class of a link are the subject of the verb (0 to 2) ("shows the documentation page" and
     * resources/js/pages/repositories/docs.tsx, or DocsController): a long word of the verb starts with its name.
     *
     * @param  array<string, mixed>  $candidate
     */
    private static function topicMatches(array $candidate, string $verb): int
    {
        $names = array_map(fn (string $path): string => (string) preg_replace('/(?<=.)[A-Z].*$/', '', pathinfo($path, PATHINFO_FILENAME)), [(string) $candidate['to'], $candidate['from']]);

        $words = array_filter(preg_split('/[^a-z]+/', strtolower($verb), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn (string $word): bool => strlen($word) >= 6);
        $matches = 0;

        foreach ($names as $name) {
            $name = rtrim(strtolower($name), 's');

            foreach ($words as $word) {
                if (strlen($name) >= 3 && str_starts_with($word, $name)) {
                    $matches++;

                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Whether a line is a declaration (a function signature or a class line).
     */
    public static function isDeclaration(string $excerpt): bool
    {
        return preg_match(self::DECLARATION, $excerpt) === 1;
    }

    /**
     * The class of the driver the configuration selects, or the documented default while AI is off.
     */
    public static function selectedDriver(): string
    {
        return self::DRIVER_CLASSES[config('gitchronicle.ai_driver')] ?? self::DRIVER_CLASSES[self::DEFAULT_DRIVER];
    }

    /**
     * The one line shown under a step: the method that holds the cited line and the statement, with a hint
     * when another driver does the same job.
     *
     * @param  array{from: string, line: int|null, excerpt: string|null}  $candidate
     */
    public function summary(Repository $repository, array $candidate): ?string
    {
        if ($candidate['line'] === null) {
            return null;
        }

        $method = DB::table('code_symbols')
            ->join('repository_files', 'repository_files.id', '=', 'code_symbols.repository_file_id')
            ->where('code_symbols.repository_id', $repository->id)
            ->where('repository_files.path', $candidate['from'])
            ->whereIn('code_symbols.kind', ['method', 'function'])
            ->where('code_symbols.start_line', '<=', $candidate['line'])
            ->where('code_symbols.end_line', '>=', $candidate['line'])
            ->orderByDesc('code_symbols.start_line')
            ->value('code_symbols.name');

        $text = trim((string) preg_replace('/\s+/', ' ', (string) $candidate['excerpt']));
        $text = $method === null ? $text : "{$method}() · {$text}";
        $others = array_diff(self::DRIVER_CLASSES, [self::selectedDriver()]);

        if (in_array(pathinfo($candidate['from'], PATHINFO_FILENAME), self::DRIVER_CLASSES, true) && $others !== []) {
            $text .= ' — or the '.(self::selectedDriver() === self::DRIVER_CLASSES['claude-code'] ? 'API' : 'Claude Code').' driver';
        }

        return mb_strimwidth($text, 0, 200, '…');
    }

    /**
     * The sort key of one candidate (smaller is better).
     *
     * @param  array<string, mixed>  $candidate
     * @param  array{kinds: list<string>, meaning: string|null}  $wanted
     * @param  array<string, mixed>  $context
     * @return list<int|string>
     */
    private static function score(array $candidate, array $wanted, array $context): array
    {
        $class = pathinfo($candidate['from'], PATHINFO_FILENAME);
        $otherDriver = $context['driver'] !== null && in_array($class, self::DRIVER_CLASSES, true) && $class !== $context['driver'];
        $reached = isset($context['reached'][$candidate['from']]) || ($candidate['to'] !== null && isset($context['reached'][$candidate['to']]));
        $named = 4 - (self::isNamedAfter($candidate['from_module'], $context['from_label']) ? 1 : 0)
            - ($candidate['to_module'] !== null && self::isNamedAfter($candidate['to_module'], $context['to_label']) ? 1 : 0)
            - (self::isFileNamedAfter($candidate['from'], $context['from_label']) ? 1 : 0)
            - ($candidate['to'] !== null && self::isFileNamedAfter($candidate['to'], $context['to_label']) ? 1 : 0);

        return [
            $otherDriver ? 1 : 0,
            self::verbTier($candidate, $wanted),
            $reached ? 0 : 1,
            $named,
            2 - self::topicMatches($candidate, $context['verb']),
            ($context['hint'] ?? null) === $candidate['from'] ? 0 : 1,
            str_contains((string) ($candidate['excerpt'] ?? ''), 'transaction') ? 1 : 0,
            $candidate['line'] === null ? 1 : 0,
            $candidate['kind'],
            $candidate['from'],
            $candidate['line'] ?? 0,
        ];
    }

    /**
     * How well the kind of a link (and, for a store, the statement) matches the verb: 0 is the best.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array{kinds: list<string>, meaning: string|null}  $wanted
     */
    private static function verbTier(array $candidate, array $wanted): int
    {
        $kind = $candidate['kind'];
        $excerpt = (string) ($candidate['excerpt'] ?? '');

        if (in_array($kind, $wanted['kinds'], true)) {
            $writes = preg_match(self::WRITES, $excerpt) === 1;
            $reads = ! $writes && preg_match(self::READS, $excerpt) === 1;

            return match (true) {
                $wanted['meaning'] === 'write' && $writes, $wanted['meaning'] === 'read' && $reads => 0,
                $wanted['meaning'] === 'write' && $reads, $wanted['meaning'] === 'read' && $writes => 2,
                default => 1,
            };
        }

        return 3;
    }

    /**
     * An import has no line of its own: the first line of the importing file that uses the imported class
     * (not the import itself), or the candidate unchanged when the clone or the class name is missing.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function locate(Repository $repository, array $candidate): array
    {
        if ($candidate['kind'] !== 'import' || $candidate['to'] === null || ! str_ends_with($candidate['from'], '.php')) {
            return $candidate;
        }

        $file = $repository->clonePath().'/'.$candidate['from'];

        if (! is_file($file) || is_link($file) || filesize($file) > 512 * 1024) {
            return $candidate;
        }

        $class = pathinfo($candidate['to'], PATHINFO_FILENAME);

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if (preg_match('/^\s*(use |namespace |\*|\/\/|\/\*)/', $line) !== 1 && preg_match('/\b'.preg_quote($class, '/').'\b/', $line) === 1 && ! self::isGuard($line)) {
                return [...$candidate, 'line' => $index + 1, 'excerpt' => trim($line)];
            }
        }

        return $candidate;
    }

    /**
     * The kinds of links an arrow's verb points to, and for a store whether it writes or reads.
     *
     * @return array{kinds: list<string>, meaning: string|null}
     */
    private static function wantedKinds(string $verb, string $targetType): array
    {
        $verb = strtolower($verb);

        return match (true) {
            preg_match('/\b(render|show|display|draw|serve)/', $verb) === 1 && $targetType !== 'data' => ['kinds' => ['render'], 'meaning' => null],
            preg_match('/\b(ask|start|trigger|dispatch|queue|hand|launch|begin|schedul|send|process)/', $verb) === 1 => ['kinds' => ['dispatch', 'queues', 'event', 'schedule', 'calls', 'runs'], 'meaning' => null],
            $targetType === 'data' && preg_match('/\b(sav|stor|persist|keep|record|insert|updat|writ|delet)/', $verb) === 1 => ['kinds' => ['stores'], 'meaning' => 'write'],
            $targetType === 'data' && preg_match('/\b(read|load|fetch|get|look|find|list|retriev|query|search|open|show)/', $verb) === 1 => ['kinds' => ['stores'], 'meaning' => 'read'],
            $targetType === 'data' => ['kinds' => ['stores'], 'meaning' => null],
            preg_match('/\b(run|execut|invok|clon|captur|call|request|writ|generat|analy|read|list|fetch|get|look)/', $verb) === 1 => ['kinds' => ['runs', 'calls'], 'meaning' => null],
            default => ['kinds' => [], 'meaning' => null],
        };
    }

    /**
     * Whether a module carries the name of a part ("app/Documentation" for "Documentation writer").
     */
    public static function isNamedAfter(string $module, string $label): bool
    {
        $segment = rtrim(strtolower(basename($module)), 's');

        if (strlen($segment) < 3) {
            return false;
        }

        foreach (preg_split('/[^a-z]+/', strtolower($label), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = rtrim($word, 's');

            if (strlen($word) >= 3 && ! in_array($word, ['app', 'and', 'the', 'code'], true) && ($word === $segment || str_starts_with($segment, $word) || str_starts_with($word, $segment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the name of a file carries a long word of a part's name ("DocumentationGenerator" for "Documentation writer").
     */
    private static function isFileNamedAfter(string $path, string $label): bool
    {
        $name = strtolower(pathinfo($path, PATHINFO_FILENAME));

        foreach (preg_split('/[^a-z]+/', strtolower($label), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) >= 7 && str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every "from id:to id" pair of two sets of modules.
     *
     * @param  array<string, int>  $from
     * @param  array<string, int>  $to
     * @return list<string>
     */
    private function pairs(array $from, array $to): array
    {
        $pairs = [];

        foreach ($from as $fromId) {
            foreach ($to as $toId) {
                $pairs[] = "{$fromId}:{$toId}";
            }
        }

        return $pairs;
    }
}
