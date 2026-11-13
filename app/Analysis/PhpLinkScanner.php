<?php

namespace App\Analysis;

/**
 * The PHP part of LinkScanner, read with PHP's own tokenizer (token_get_all), so comments never match
 * and strings are known to be strings. Two passes over the PHP files of a repository:
 *
 * 1. index(): what each file declares — its namespace and classes, whether it is a queued job, an event,
 *    an Eloquent model or an Artisan command (and its name), whether it starts other programs or sends
 *    HTTP requests, and which configuration keys name a program (config/*.php);
 * 2. scan(): what each file points to that its `use` lines do not show —
 *    - a class of the same namespace used without a `use` line ("reference"),
 *    - Laravel's own connections: a route to a controller ("route"), a job dispatched or chained
 *      ("dispatch"), an event fired ("event"), an Inertia page or a Blade view rendered ("render"),
 *      a class bound in a service provider or resolved from the container ("binding"), a command in the
 *      schedule ("schedule"),
 *    - programs and scripts it starts ("runs"), services it calls ("calls"), and where it keeps data
 *      ("stores": database, cache, files) or queues work ("queues").
 * A class reference that a `use` line already shows is stored only when it has one of the Laravel kinds,
 * so the module map can say "dispatches" instead of "uses".
 */
final class PhpLinkScanner
{
    /**
     * Words that look like class names but are not.
     */
    private const NOT_CLASSES = ['self', 'static', 'parent', 'true', 'false', 'null', 'array', 'string', 'int', 'float', 'bool', 'mixed', 'void', 'never', 'callable', 'iterable', 'object'];

    /**
     * Facades that keep data => store kind.
     */
    private const STORE_FACADES = ['DB' => 'database', 'Schema' => 'database', 'Cache' => 'cache', 'Redis' => 'cache', 'Storage' => 'files'];

    /**
     * Calls that queue a job or fire an event when they take `new X(...)` or `X::class`.
     */
    private const DISPATCH_WORDS = ['dispatch', 'dispatch_sync', 'dispatchSync', 'dispatchAfterResponse', 'chain', 'batch', 'push', 'later', 'withChain', 'event', 'broadcast'];

    /**
     * Static methods of a job or event class that start it.
     */
    private const DISPATCH_METHODS = ['dispatch', 'dispatchSync', 'dispatchIf', 'dispatchUnless', 'dispatchAfterResponse', 'withChain'];

    /**
     * Functions and methods that resolve a class from the container.
     */
    private const CONTAINER_WORDS = ['app', 'resolve', 'make', 'bind', 'singleton', 'scoped', 'instance', 'when', 'needs', 'give', 'extend', 'tag'];

    /**
     * Functions that start another program.
     */
    private const PROCESS_FUNCTIONS = ['proc_open', 'shell_exec', 'passthru', 'popen', 'exec', 'system', 'pcntl_exec'];

    /**
     * What index() learned about every PHP file (path => facts), and the repository-wide lookups.
     *
     * @var array<string, array{namespace: string, classes: list<string>, job: bool, event: bool, model: bool, process_line: int|null, process_text: string, http: bool}>
     */
    private array $files = [];

    /**
     * Fully qualified class name => path of the file that declares it.
     *
     * @var array<string, string>
     */
    private array $classes = [];

    /**
     * Artisan command name => path.
     *
     * @var array<string, string>
     */
    private array $commands = [];

    /**
     * Configuration key ("gitchronicle.claude_code_bin") => the string literals of its value ("claude").
     *
     * @var array<string, list<string>>
     */
    private array $configValues = [];

    /**
     * Learn what one PHP file declares (first pass).
     */
    public function index(string $path, string $source): void
    {
        $tokens = self::tokens($source);
        $lines = explode("\n", $source);
        $namespace = '';
        $classes = [];
        $facts = ['job' => str_contains($path, '/Jobs/'), 'event' => str_contains($path, '/Events/'), 'model' => false, 'process_line' => null, 'process_text' => '', 'http' => false];

        foreach ($tokens as $index => [$type, $text, $line]) {
            $previous = $tokens[$index - 1][1] ?? '';
            $next = $tokens[$index + 1][1] ?? '';

            if ($type === T_NAMESPACE && in_array($tokens[$index + 1][0] ?? null, [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $next;
            } elseif (in_array($type, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $previous !== '::' && $previous !== 'new' && ($tokens[$index + 1][0] ?? null) === T_STRING) {
                $classes[] = ltrim("{$namespace}\\{$next}", '\\');
            } elseif ($text === 'ShouldQueue' && in_array($previous, ['implements', ','], true)) {
                $facts['job'] = true;
            } elseif ($previous === 'extends' && in_array(self::shortName($text), ['Model', 'Authenticatable', 'Pivot'], true)) {
                $facts['model'] = true;
            } elseif ($type === T_VARIABLE && $text === '$signature' && $next === '=' && ($tokens[$index + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                $this->commands[self::firstWord(self::unquote($tokens[$index + 2][1]))] = $path;
            } elseif ($text === 'AsCommand' && $next === '(') {
                $literal = $tokens[$index + 2][0] === T_CONSTANT_ENCAPSED_STRING ? $tokens[$index + 2] : ($tokens[$index + 4] ?? null);

                if (($literal[0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                    $this->commands[self::firstWord(self::unquote($literal[1]))] = $path;
                }
            } elseif ($facts['process_line'] === null && self::isProcessCall($tokens, $index)) {
                $facts['process_line'] = $line;
                $facts['process_text'] = trim($lines[$line - 1] ?? '');
            } elseif (($text === 'Http' && $next === '::') || str_contains($text, 'GuzzleHttp') || $text === 'curl_init') {
                $facts['http'] = true;
            }
        }

        foreach ($classes as $class) {
            $this->classes[$class] = $path;
        }

        $this->files[$path] = ['namespace' => $namespace, 'classes' => $classes, ...$facts];

        if (preg_match('#(^|/)config/([\w-]+)\.php$#', $path, $match) === 1) {
            $this->indexConfiguration($match[2], $tokens);
        }
    }

    /**
     * The links of one PHP file (second pass, after every file was indexed).
     *
     * @return list<array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}>
     */
    public function scan(string $path, string $source, LinkTargets $targets): array
    {
        $tokens = self::tokens($source);
        $lines = explode("\n", $source);
        $facts = $this->files[$path] ?? ['namespace' => '', 'classes' => [], 'job' => false, 'event' => false, 'model' => false, 'process_line' => null, 'process_text' => '', 'http' => false];
        $imports = $this->imports($tokens);
        $isRoutes = preg_match('#(^|/)routes/[^/]+\.php$#', $path) === 1;
        $isProvider = str_ends_with($path, 'ServiceProvider.php');
        $words = array_flip(array_map(fn (array $token): string => strtolower($token[1]), array_filter($tokens, fn (array $token): bool => $token[0] === T_STRING)));
        $rendersPages = isset($words['inertia']) || count(array_filter(array_keys($words), fn (string $word): bool => str_contains($word, 'render'))) > 0;
        $dispatches = array_intersect_key($words, array_flip(array_map('strtolower', self::DISPATCH_WORDS))) !== [];
        $schedules = $isRoutes || str_ends_with($path, 'Kernel.php') || isset($words['schedule']);
        $runsPrograms = $facts['process_line'] !== null;
        $links = [];
        $add = function (int $line, string $kind, ?string $to, ?string $target) use (&$links, $path, $lines): void {
            $links["{$line}|{$kind}|{$to}|{$target}"] = [
                'from' => $path, 'line' => $line, 'kind' => $kind, 'to' => $to, 'target' => $target,
                'excerpt' => LinkScanner::excerpt($lines[$line - 1] ?? ''),
            ];
        };

        if ($facts['model']) {
            $add($this->declarationLine($tokens), 'stores', null, 'store:database');
        }

        if ($facts['job'] && str_contains($source, 'ShouldQueue')) {
            $add($this->implementsLine($tokens, 'ShouldQueue'), 'queues', null, 'store:queue');
        }

        $depth = 0;
        $skipUntil = -1;

        foreach ($tokens as $index => [$type, $text, $line]) {
            $previous = $tokens[$index - 1][1] ?? '';
            $next = $tokens[$index + 1][1] ?? '';
            $depth += match ($text) {
                '{' => 1, '}' => -1, default => 0,
            };

            // The `use` lines at the top are the imports themselves; a `use Trait;` inside a class is a reference.
            if ($type === T_USE && $depth === 0 && $next !== '(') {
                $skipUntil = $this->endOfStatement($tokens, $index);
            }

            if ($index <= $skipUntil) {
                continue;
            }

            if (in_array($type, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if (isset(self::STORE_FACADES[$text]) && $next === '::') {
                    $add($line, 'stores', null, 'store:'.self::STORE_FACADES[$text]);
                } elseif ($text === 'Mail' && $next === '::') {
                    $add($line, 'calls', null, 'service:mail');
                } elseif ($text === 'config' && $next === '(' && ($tokens[$index + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                    $this->configLink(self::unquote($tokens[$index + 2][1]), $tokens, $index, $imports, $facts, $runsPrograms, $line, $add, $targets);
                } elseif ($runsPrograms && $previous === '->' && ($tool = self::toolOfName($text)) !== null) {
                    $add($line, 'runs', null, "tool:{$tool}");
                }

                $class = $this->classReference($tokens, $index, $imports, $facts['namespace']);

                if ($class !== null && $this->classes[$class] !== $path) {
                    $kind = $this->referenceKind($tokens, $index, $class, $path, $isRoutes, $isProvider, $dispatches, $schedules);
                    $isImported = isset($imports[strtolower(self::firstSegment($text))]) || $type === T_NAME_FULLY_QUALIFIED;

                    if ($kind !== 'reference' || ! $isImported) {
                        $add($line, $kind, $this->classes[$class], null);
                    }

                    if ($kind === 'dispatch' && $this->files[$this->classes[$class]]['job']) {
                        $add($line, 'queues', null, 'store:queue');
                    }
                }

                continue;
            }

            if ($type === T_VARIABLE && $runsPrograms && ($tool = self::toolOfName(substr($text, 1))) !== null) {
                $add($line, 'runs', null, "tool:{$tool}");

                continue;
            }

            if ($type !== T_CONSTANT_ENCAPSED_STRING && $type !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            $string = $type === T_CONSTANT_ENCAPSED_STRING ? self::unquote($text) : $text;

            foreach (LinkScanner::urlHosts($string) as $host) {
                $service = LinkCatalog::service($host);

                // A file that sends HTTP requests calls the host; elsewhere an address of a known service is a link to it.
                if ($service !== null && ($facts['http'] || $runsPrograms)) {
                    $add($line, 'calls', null, "service:{$service}");
                } elseif ($service !== null && $service !== $host) {
                    $add($line, 'links', null, "service:{$service}");
                }
            }

            if ($type !== T_CONSTANT_ENCAPSED_STRING || strlen($string) > 200) {
                continue;
            }

            if ($rendersPages && ($page = $targets->page($string)) !== null && self::isRenderCall(self::enclosingCall($tokens, $index))) {
                $add($line, 'render', $page, null);
            } elseif ($previous === '(' && in_array($tokens[$index - 2][1] ?? '', ['view', 'make'], true) && ($view = $targets->view($string)) !== null) {
                $add($line, 'render', $view, null);
            } elseif (($command = $this->commands[self::firstWord($string)] ?? null) !== null && $command !== $path && str_contains($string, ':')) {
                $add($line, $schedules ? 'schedule' : 'dispatch', $command, null);
            } elseif (($runsPrograms || str_starts_with($path, 'config/')) && ($script = $targets->script($string)) !== null && $script !== $path) {
                $add($line, 'runs', $script, null);
            } elseif ($runsPrograms && ($tool = LinkCatalog::tool($string)) !== null && (basename($string) === $tool || $this->isProcessArgument($tokens, $index))) {
                $add($line, 'runs', null, "tool:{$tool}");
            } elseif ($isRoutes && preg_match('/\A([\w\\\\]+)@\w+\z/', $string, $match) === 1 && ($controller = $this->controllerNamed($match[1])) !== null) {
                $add($line, 'route', $controller, null);
            }
        }

        foreach ($imports as $className) {
            if (($service = LinkCatalog::sdk($className, 'PHP')) !== null) {
                $add($this->lineOf($tokens, self::shortName($className)), 'calls', null, "service:{$service}");
            }
        }

        return array_values($links);
    }

    /**
     * The links that belong to another file than the one they were found in: a service provider that
     * builds `new Runner(binary: config('x.binary'))`, where the configuration names a program and the
     * Runner class starts processes, means that Runner runs that program. Returned by scan() of the
     * provider through $add would put the provider's line on the runner, so they are collected here.
     *
     * @var list<array{from: string, line: int, kind: string, to: string|null, target: string|null, excerpt: string}>
     */
    public array $deferred = [];

    /**
     * A config('key') call: when the key names a program, the file runs it if it starts processes itself;
     * inside `new X(... config('key') ...)` the program is run by X when X starts processes.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     * @param  array<string, string>  $imports
     * @param  array<string, mixed>  $facts
     */
    private function configLink(string $key, array $tokens, int $index, array $imports, array $facts, bool $runsPrograms, int $line, callable $add, LinkTargets $targets): void
    {
        $values = $this->configValues[$key] ?? [];
        $tool = null;

        foreach ($values as $literal) {
            $tool ??= basename($literal) === LinkCatalog::tool($literal) || $literal === LinkCatalog::tool($literal) ? LinkCatalog::tool($literal) : null;

            if ($runsPrograms && ($script = $targets->script($literal)) !== null) {
                $add($line, 'runs', $script, null);
            }
        }

        if ($tool === null) {
            return;
        }

        if ($runsPrograms) {
            $add($line, 'runs', null, "tool:{$tool}");

            return;
        }

        for ($back = $index - 1; $back >= max(0, $index - 25); $back--) {
            if ($tokens[$back][1] === ';') {
                return;
            }

            if ($tokens[$back][1] === 'new') {
                $class = $this->classReference($tokens, $back + 1, $imports, $facts['namespace']);
                $runner = $class === null ? null : $this->classes[$class];
                $processLine = $runner === null ? null : $this->files[$runner]['process_line'];

                if ($processLine !== null) {
                    $this->deferred[] = [
                        'from' => $runner, 'line' => $processLine, 'kind' => 'runs', 'to' => null, 'target' => "tool:{$tool}",
                        'excerpt' => LinkScanner::excerpt("{$this->files[$runner]['process_text']} [{$tool} from config('{$key}')]"),
                    ];
                }

                return;
            }
        }
    }

    /**
     * How a referenced class is connected, from where it is used and what it is.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function referenceKind(array $tokens, int $index, string $class, string $path, bool $isRoutes, bool $isProvider, bool $dispatches, bool $schedules): string
    {
        $targetPath = $this->classes[$class];
        $target = $this->files[$targetPath];
        $next = $tokens[$index + 1][1] ?? '';
        $method = $tokens[$index + 2][1] ?? '';
        $isCommand = in_array($targetPath, $this->commands, true);

        if ($isRoutes && (str_contains($targetPath, 'Controller') || str_contains($targetPath, '/Http/'))) {
            return 'route';
        }

        if ($next === '::' && in_array($method, self::DISPATCH_METHODS, true)) {
            return $target['event'] ? 'event' : 'dispatch';
        }

        $before = array_map(fn (array $token): string => $token[1], array_slice($tokens, max(0, $index - 12), min(12, $index)));

        if ($target['event'] && ($before !== [] && (in_array('event', $before, true) || in_array('broadcast', $before, true) || in_array('dispatch', $before, true)))) {
            return 'event';
        }

        if ($target['job'] && $dispatches) {
            return 'dispatch';
        }

        if ($isCommand && $schedules) {
            return 'schedule';
        }

        if ($isProvider || array_intersect($before, self::CONTAINER_WORDS) !== [] && in_array('(', $before, true) && $next === '::' && $method === 'class') {
            return 'binding';
        }

        return 'reference';
    }

    /**
     * The fully qualified class a name token stands for, when it is a class of this repository used
     * as a class (not a method, a property, a constant or a named argument), or null.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     * @param  array<string, string>  $imports
     */
    private function classReference(array $tokens, int $index, array $imports, string $namespace): ?string
    {
        [$type, $text] = $tokens[$index];
        $previous = $tokens[$index - 1][1] ?? '';
        $next = $tokens[$index + 1][1] ?? '';

        if (! in_array($type, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            || in_array(strtolower($text), self::NOT_CLASSES, true)
            || in_array($previous, ['->', '?->', '::', 'function', 'const', 'namespace', 'class', 'interface', 'trait', 'enum', 'goto', 'case'], true)
            || ($next === '(' && $previous !== 'new')
            || ($next === ':' && $previous !== '?')) {
            return null;
        }

        if ($type === T_NAME_FULLY_QUALIFIED) {
            $class = ltrim($text, '\\');
        } else {
            $first = strtolower(self::firstSegment($text));
            $rest = str_contains($text, '\\') ? substr($text, strpos($text, '\\')) : '';
            $class = isset($imports[$first]) ? $imports[$first].$rest : ltrim("{$namespace}\\{$text}", '\\');
        }

        return isset($this->classes[$class]) ? $class : null;
    }

    /**
     * The top `use` lines: lower-case alias => fully qualified class name (groups and aliases included).
     *
     * @param  list<array{int|string, string, int}>  $tokens
     * @return array<string, string>
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        $depth = 0;

        foreach ($tokens as $index => [$type, $text]) {
            $depth += match ($text) {
                '{' => 1, '}' => -1, default => 0,
            };

            if ($type !== T_USE || $depth !== 0 || ($tokens[$index + 1][1] ?? '') === '(') {
                continue;
            }

            $prefix = '';
            $name = null;

            for ($i = $index + 1; isset($tokens[$i]) && $tokens[$i][1] !== ';'; $i++) {
                [$partType, $part] = $tokens[$i];

                if (in_array($partType, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ! in_array(strtolower($part), ['function', 'const'], true)) {
                    if (($tokens[$i - 1][1] ?? '') === 'as' && $name !== null) {
                        $imports[strtolower($part)] = $name;
                        $name = null;
                    } else {
                        $name = ltrim($prefix.$part, '\\');
                        $imports[strtolower(self::shortName($name))] = $name;
                    }
                } elseif ($part === '{') {
                    $prefix = rtrim((string) $name, '\\').'\\';
                    unset($imports[strtolower(self::shortName((string) $name))]);
                    $name = null;
                }
            }
        }

        return $imports;
    }

    /**
     * Remember the string literals of every configuration value, by its dotted key, so a config('key') call
     * can be followed to the program or the script it names ('ffmpeg_path' => '/usr/bin/ffmpeg',
     * 'claude_code_bin' => env('CLAUDE_CODE_BIN', 'claude'), 'script' => base_path('renderers/tts/speak.py')).
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function indexConfiguration(string $file, array $tokens): void
    {
        $keys = [];
        $pending = null;

        foreach ($tokens as $index => [$type, $text]) {
            if ($type === T_CONSTANT_ENCAPSED_STRING && ($tokens[$index + 1][1] ?? '') === '=>') {
                $pending = self::unquote($text);
                $value = [];

                for ($i = $index + 2, $depth = 0; isset($tokens[$i]); $i++) {
                    $part = $tokens[$i][1];
                    $depth += in_array($part, ['(', '['], true) ? 1 : (in_array($part, [')', ']'], true) ? -1 : 0);

                    if ($depth < 0 || ($depth === 0 && $part === ',')) {
                        break;
                    }

                    if ($tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $value[] = self::unquote($part);
                    }
                }

                if ($value !== []) {
                    $this->configValues[implode('.', [$file, ...$keys, $pending])] = $value;
                }
            } elseif ($text === '[' && ($tokens[$index - 1][1] ?? '') === '=>' && $pending !== null) {
                $keys[] = $pending;
                $pending = null;
            } elseif ($text === ']' && $keys !== [] && $this->closesKeyedArray($tokens, $index)) {
                array_pop($keys);
            }
        }
    }

    /**
     * Whether this "]" closes an array that was opened right after a key (an approximation that holds for
     * the usual config files: a nested array ends with "]," or "]" before the parent's "]").
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function closesKeyedArray(array $tokens, int $index): bool
    {
        $depth = 0;

        for ($i = $index; $i >= 0; $i--) {
            $depth += $tokens[$i][1] === ']' ? 1 : ($tokens[$i][1] === '[' ? -1 : 0);

            if ($depth === 0) {
                return ($tokens[$i - 1][1] ?? '') === '=>';
            }
        }

        return false;
    }

    /**
     * Whether the token starts another program: Process::…, new Process(…), proc_open(…), exec(…), …,
     * or ->run([...]) / ->start([...]) with a command array.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private static function isProcessCall(array $tokens, int $index): bool
    {
        $text = $tokens[$index][1];
        $previous = $tokens[$index - 1][1] ?? '';
        $next = $tokens[$index + 1][1] ?? '';

        return ($text === 'Process' && ($next === '::' || ($previous === 'new' && $next === '(')))
            || (in_array($text, self::PROCESS_FUNCTIONS, true) && $next === '(' && ! in_array($previous, ['->', '::', 'function'], true))
            || (in_array($text, ['run', 'start', 'mustRun'], true) && $previous === '->' && $next === '(' && ($tokens[$index + 2][1] ?? '') === '[');
    }

    /**
     * The name of the call whose parentheses hold the token ("renderRepositoryPage" for its third argument), or ''.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private static function enclosingCall(array $tokens, int $index): string
    {
        $depth = 0;

        for ($i = $index - 1; $i >= max(0, $index - 60); $i--) {
            $text = $tokens[$i][1];

            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '(' || $text === '[') {
                if ($depth === 0) {
                    return $text === '(' ? (string) ($tokens[$i - 1][1] ?? '') : '';
                }

                $depth--;
            }
        }

        return '';
    }

    /**
     * Whether a call name renders a page: Inertia::render, inertia(), Route::inertia or a helper named *render*.
     */
    private static function isRenderCall(string $name): bool
    {
        return str_contains(strtolower($name), 'render') || strtolower($name) === 'inertia';
    }

    /**
     * Whether a string is a command given to a process call on the same line ("Process::run('git status')").
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function isProcessArgument(array $tokens, int $index): bool
    {
        $call = self::enclosingCall($tokens, $index);

        return in_array($call, ['run', 'start', 'mustRun', 'Process', ...self::PROCESS_FUNCTIONS], true);
    }

    /**
     * The tool a variable or property name stands for: $ffmpeg, $this->gitBinary, $claudePath.
     */
    private static function toolOfName(string $name): ?string
    {
        $program = strtolower((string) preg_replace('/(Binary|Bin|Path|Executable|Command)$/', '', $name));

        return isset(LinkCatalog::TOOLS[$program]) && $program !== 'convert' ? LinkCatalog::tool($program) : null;
    }

    /**
     * The controller with this short or qualified name ("PhotoController" from 'PhotoController@show'), or null.
     */
    private function controllerNamed(string $name): ?string
    {
        foreach ($this->classes as $class => $path) {
            if (str_contains($path, 'Controller') && ($class === ltrim($name, '\\') || str_ends_with($class, '\\'.ltrim($name, '\\')))) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The index of the ";" that ends the statement starting at $index.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function endOfStatement(array $tokens, int $index): int
    {
        for ($i = $index; isset($tokens[$i]); $i++) {
            if ($tokens[$i][1] === ';') {
                return $i;
            }
        }

        return count($tokens);
    }

    /**
     * The line of the first class, interface, trait or enum declaration.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function declarationLine(array $tokens): int
    {
        foreach ($tokens as $index => [$type, , $line]) {
            if (in_array($type, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ($tokens[$index - 1][1] ?? '') !== '::') {
                return $line;
            }
        }

        return 1;
    }

    /**
     * The line of "implements … Interface …", or the first line that names the interface.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function implementsLine(array $tokens, string $interface): int
    {
        foreach ($tokens as $index => [, $text, $line]) {
            if (self::shortName($text) === $interface && in_array($tokens[$index - 1][1] ?? '', ['implements', ','], true)) {
                return $line;
            }
        }

        return $this->lineOf($tokens, $interface);
    }

    /**
     * The line where a word first appears as code.
     *
     * @param  list<array{int|string, string, int}>  $tokens
     */
    private function lineOf(array $tokens, string $word): int
    {
        foreach ($tokens as [, $text, $line]) {
            if ($text === $word || str_ends_with($text, "\\{$word}")) {
                return $line;
            }
        }

        return 1;
    }

    /**
     * The tokens of the source without whitespace and comments, as [type, text, line]; a one-character
     * token gets the type "" and the line of the token before it.
     *
     * @return list<array{int|string, string, int}>
     */
    private static function tokens(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                    $tokens[] = [$token[0], $token[1], $line];
                }

                $line += substr_count($token[1], "\n");
            } else {
                $tokens[] = ['', $token, $line];
            }
        }

        return $tokens;
    }

    /**
     * The text of a PHP string literal without its quotes.
     */
    private static function unquote(string $literal): string
    {
        return strlen($literal) >= 2 ? substr($literal, 1, -1) : $literal;
    }

    /**
     * The first word of a command ("repositories:process {id?}" → "repositories:process").
     */
    private static function firstWord(string $text): string
    {
        return (string) strtok(trim($text), " \t");
    }

    /**
     * The last part of a class name ("App\Jobs\CloneRepository" → "CloneRepository").
     */
    private static function shortName(string $className): string
    {
        $position = strrpos($className, '\\');

        return $position === false ? $className : substr($className, $position + 1);
    }

    /**
     * The first part of a name ("Jobs\CloneRepository" → "Jobs").
     */
    private static function firstSegment(string $name): string
    {
        return explode('\\', ltrim($name, '\\'))[0];
    }
}
