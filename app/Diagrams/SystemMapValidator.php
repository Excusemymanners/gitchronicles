<?php

namespace App\Diagrams;

/**
 * Checks the big picture the text generator proposes against the facts of the code (SystemFacts), part by
 * part and arrow by arrow, so the picture is both meaningful and true:
 *
 * - a part that stands for code lists real application modules, each in one part only; a part that stands
 *   for something outside the code (external system, tool, store) lists the evidence ids it stands for,
 *   and its kind must be the kind of that evidence;
 * - an arrow between two parts of code must be backed by at least one real link (an import, a dispatch,
 *   a route, ...) from a module of the first to a module of the second; an arrow to or from something
 *   outside the code must be backed by evidence that a module of the part uses it;
 * - no file or class names in the words of a box or an arrow (a box says what a part IS for a person);
 * - an unbacked arrow is dropped and counted; a part left without arrows is dropped; when fewer than five
 *   parts or fewer than 60 % of the proposed arrows survive, the whole answer is rejected.
 * Every arrow that survives keeps its backing, for the evidence table under the picture.
 */
final class SystemMapValidator
{
    /**
     * The kinds of a part.
     */
    public const NODE_KINDS = ['interface', 'service', 'worker', 'data', 'external', 'tool'];

    /**
     * Kinds of a part that stands for code of the repository.
     */
    public const CODE_KINDS = ['interface', 'service', 'worker'];

    /**
     * The fewest parts a big picture may have.
     */
    public const MIN_NODES = 5;

    /**
     * The most parts a big picture may have.
     */
    public const MAX_NODES = 9;

    /**
     * The share of the proposed arrows that must survive.
     */
    public const MIN_SURVIVING_SHARE = 0.6;

    /**
     * Names written the way products write them, allowed although they look like class names.
     */
    private const BRAND_NAMES = ['GitHub', 'GitLab', 'FFmpeg', 'OpenAI', 'JavaScript', 'TypeScript', 'PostgreSQL', 'MySQL', 'SQLite', 'MongoDB', 'MariaDB', 'NodeJS', 'PyPI', 'YouTube', 'WebSocket', 'WebSockets', 'iOS', 'macOS', 'GraphQL', 'RabbitMQ', 'DynamoDB', 'BigQuery', 'ImageMagick', 'LibreOffice', 'RustDesk', 'HyperFrames', 'OAuth', 'JSON', 'HTML', 'README', 'PowerShell', 'DevTools', 'LaTeX', 'WebRTC', 'VSCode', 'OpenAPI', 'PostHog', 'ElasticSearch', 'TeamCity', 'GitChronicle', 'SemVer', 'npm'];

    /**
     * Validate an answer against the facts.
     *
     * @param  array<mixed>  $answer  the decoded JSON
     * @param  array{modules: array<string, array<string, mixed>>, links: list<array{from: string, to: string, weight: int, kinds: array<string, int>}>, externals: list<array{module: string, target: string, kind: string, count: int}>, targets: array<string, array{name: string, type: string, count: int}>}  $facts
     * @return array{map: array<string, mixed>|null, reason: string|null, stats: array{proposed_nodes: int, proposed_edges: int, dropped_nodes: int, dropped_edges: int, fixed: int}}
     */
    public static function validate(array $answer, array $facts): array
    {
        $stats = ['proposed_nodes' => 0, 'proposed_edges' => 0, 'dropped_nodes' => 0, 'dropped_edges' => 0, 'fixed' => 0];
        $nodes = [];
        $usedModules = [];

        foreach (array_slice(is_array($answer['nodes'] ?? null) ? $answer['nodes'] : [], 0, 14) as $proposed) {
            $stats['proposed_nodes']++;
            $node = is_array($proposed) ? self::part($proposed, $facts, $usedModules, $stats) : null;

            if ($node === null || isset($nodes[$node['id']])) {
                $stats['dropped_nodes']++;

                continue;
            }

            $nodes[$node['id']] = $node;
        }

        $edges = [];

        foreach (is_array($answer['edges'] ?? null) ? $answer['edges'] : [] as $index => $proposed) {
            $stats['proposed_edges']++;
            $edge = is_array($proposed) ? self::edge($proposed, $index, $nodes, $facts) : null;

            // One arrow per pair of parts: a second one between the same two says nothing new.
            if ($edge === null || isset($edges[$edge['id']]) || in_array("{$edge['from']}>{$edge['to']}", array_map(fn (array $kept): string => "{$kept['from']}>{$kept['to']}", $edges), true)) {
                $stats['dropped_edges']++;

                continue;
            }

            $edges[$edge['id']] = $edge;
        }

        // A part left without any arrow says nothing about how the project works.
        $connected = [];

        foreach ($edges as $edge) {
            $connected[$edge['from']] = true;
            $connected[$edge['to']] = true;
        }

        foreach (array_keys($nodes) as $id) {
            if (! isset($connected[$id])) {
                unset($nodes[$id]);
                $stats['dropped_nodes']++;
            }
        }

        $reason = match (true) {
            $stats['proposed_edges'] === 0 => 'no arrows',
            count($nodes) < self::MIN_NODES => 'fewer than '.self::MIN_NODES.' parts survived',
            count($nodes) > self::MAX_NODES => 'more than '.self::MAX_NODES.' parts',
            count($edges) / $stats['proposed_edges'] < self::MIN_SURVIVING_SHARE => 'fewer than 60 % of the arrows are backed by the code',
            default => null,
        };

        if ($reason !== null) {
            return ['map' => null, 'reason' => $reason, 'stats' => $stats];
        }

        $step = 0;

        foreach (is_array($answer['main_path'] ?? null) ? $answer['main_path'] : [] as $edgeId) {
            if (is_string($edgeId) && isset($edges[$edgeId]) && $edges[$edgeId]['step'] === null) {
                $edges[$edgeId]['step'] = ++$step;
            }
        }

        return [
            'map' => [
                'title' => self::text($answer['title'] ?? null, 80) ?? '',
                'reading' => self::text($answer['reading'] ?? null, 420) ?? '',
                'nodes' => array_values($nodes),
                'edges' => array_values($edges),
            ],
            'reason' => null,
            'stats' => $stats,
        ];
    }

    /**
     * Whether a text names code (a path, a file, a namespace, a call or a CamelCase class name) instead of
     * saying what something is for a person.
     */
    public static function namesCode(string $text): bool
    {
        // Product names with a dot ("Node.js", "Next.js") are names, not files.
        $text = (string) preg_replace('/\b[A-Z]\w*\.js\b/', 'Product', $text);

        if (preg_match('#[/\\\\]|::|\(\)|\.(php|py|ts|tsx|js|jsx|mjs|go|rs|sh|vue|json)\b|\b\w+_\w+\b#i', $text) === 1) {
            return true;
        }

        preg_match_all('/\b[A-Za-z]*[a-z][A-Z]\w*\b/', $text, $matches);

        return array_diff($matches[0], self::BRAND_NAMES) !== [];
    }

    /**
     * One part, checked, or null when it cannot be kept.
     *
     * @param  array<mixed>  $proposed
     * @param  array<string, mixed>  $facts
     * @param  array<string, string>  $usedModules  module path or evidence id => the part that took it
     * @param  array<string, int>  $stats
     * @return array{id: string, label: string, does: string|null, kind: string, modules: list<string>}|null
     */
    public static function part(array $proposed, array $facts, array &$usedModules, array &$stats): ?array
    {
        $id = is_string($proposed['id'] ?? null) ? strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', $proposed['id'])) : '';
        $label = self::text($proposed['label'] ?? null, 40);
        $kind = $proposed['kind'] ?? null;

        if ($id === '' || $label === null || str_word_count($label) > 5 || self::namesCode($label) || ! in_array($kind, self::NODE_KINDS, true)) {
            return null;
        }

        $does = self::text($proposed['does'] ?? null, 120);
        $does = $does !== null && (self::namesCode($does) || str_word_count($does) > 18) ? null : $does;
        $isCode = in_array($kind, self::CODE_KINDS, true);
        $modules = [];

        foreach (is_array($proposed['modules'] ?? null) ? $proposed['modules'] : [] as $module) {
            if (! is_string($module) || isset($usedModules[$module])) {
                continue;
            }

            if (isset($facts['modules'][$module]) || isset($facts['targets'][$module])) {
                $modules[] = $module;
            }
        }

        $codeModules = array_values(array_filter($modules, fn (string $module): bool => isset($facts['modules'][$module])));
        $targets = array_values(array_filter($modules, fn (string $module): bool => isset($facts['targets'][$module])));

        // A part is code or something outside the code, never both; the evidence decides which.
        if ($isCode && $codeModules === [] && $targets !== []) {
            $isCode = false;
            $stats['fixed']++;
        } elseif (! $isCode && $targets === [] && $codeModules !== []) {
            $isCode = true;
            $kind = 'service';
            $stats['fixed']++;
        }

        $modules = $isCode ? $codeModules : $targets;

        if ($modules === []) {
            return null;
        }

        $kind = $isCode ? self::codeKind($kind, $modules, $facts, $stats) : self::outsideKind($kind, $modules, $facts, $stats);

        foreach ($modules as $module) {
            $usedModules[$module] = $id;
        }

        return ['id' => $id, 'label' => $label, 'does' => $does, 'kind' => $kind, 'modules' => $modules];
    }

    /**
     * The kind of a part of code, corrected to what the evidence says: a "worker" must hold queued jobs or
     * scheduled commands, an "interface" must hold pages, views, routes or controllers.
     *
     * @param  list<string>  $modules
     * @param  array<string, mixed>  $facts
     * @param  array<string, int>  $stats
     */
    private static function codeKind(string $kind, array $modules, array $facts, array &$stats): string
    {
        $isWorker = false;
        $isInterface = false;

        foreach ($modules as $module) {
            $isInterface = $isInterface || in_array($facts['modules'][$module]['layer'], ['ui', 'web', 'entry'], true);
            $isWorker = $isWorker || preg_match('#(^|/)(jobs|workers?|queues?|tasks|console|commands|cron|schedulers?)(/|$)#i', $module) === 1;
        }

        foreach ($facts['externals'] as $row) {
            $isWorker = $isWorker || (in_array($row['module'], $modules, true) && $row['target'] === 'store:queue');
        }

        foreach ($facts['links'] as $link) {
            $isWorker = $isWorker || (in_array($link['to'], $modules, true) && (isset($link['kinds']['dispatch']) || isset($link['kinds']['schedule'])));
        }

        $correct = match ($kind) {
            'worker' => $isWorker,
            'interface' => $isInterface,
            default => true,
        };

        if ($correct) {
            return $kind;
        }

        $stats['fixed']++;

        return 'service';
    }

    /**
     * The kind of a part outside the code: the type of the evidence it stands for (tool, external, data).
     *
     * @param  list<string>  $targets
     * @param  array<string, mixed>  $facts
     * @param  array<string, int>  $stats
     */
    private static function outsideKind(string $kind, array $targets, array $facts, array &$stats): string
    {
        $type = $facts['targets'][$targets[0]]['type'];

        if ($type !== $kind) {
            $stats['fixed']++;
        }

        return $type;
    }

    /**
     * One arrow, with its backing, or null when it is not backed or not well formed.
     *
     * @param  array<mixed>  $proposed
     * @param  array<string, array{id: string, kind: string, modules: list<string>}>  $nodes
     * @param  array<string, mixed>  $facts
     * @return array{id: string, from: string, to: string, verb: string, carries: string|null, step: int|null, backing: array<string, mixed>}|null
     */
    private static function edge(array $proposed, int $index, array $nodes, array $facts): ?array
    {
        $from = is_string($proposed['from'] ?? null) ? strtolower($proposed['from']) : '';
        $to = is_string($proposed['to'] ?? null) ? strtolower($proposed['to']) : '';
        $verb = self::text($proposed['verb'] ?? null, 40);

        if (! isset($nodes[$from], $nodes[$to]) || $from === $to || $verb === null || str_word_count($verb) > 5 || self::namesCode($verb)) {
            return null;
        }

        $carries = self::text($proposed['carries'] ?? null, 50);
        $carries = $carries !== null && self::namesCode($carries) ? null : $carries;
        $backing = self::backing($nodes[$from], $nodes[$to], $facts);

        if ($backing === null) {
            return null;
        }

        $id = is_string($proposed['id'] ?? null) && $proposed['id'] !== '' ? (string) preg_replace('/[^A-Za-z0-9_]/', '', $proposed['id']) : 'e'.($index + 1);

        return ['id' => $id, 'from' => $from, 'to' => $to, 'verb' => $verb, 'carries' => $carries, 'step' => null, 'backing' => $backing];
    }

    /**
     * What backs an arrow between two parts: the links from the modules of one to the modules of the other
     * (in the arrow's direction), or the evidence that the part of code uses the thing outside it; null
     * when nothing does. Two things outside the code are never connected by the code.
     *
     * @param  array{kind: string, modules: list<string>}  $from
     * @param  array{kind: string, modules: list<string>}  $to
     * @param  array<string, mixed>  $facts
     * @return array{type: string, rows: list<array<string, mixed>>}|null
     */
    public static function backing(array $from, array $to, array $facts): ?array
    {
        $fromIsCode = in_array($from['kind'], self::CODE_KINDS, true);
        $toIsCode = in_array($to['kind'], self::CODE_KINDS, true);

        if ($fromIsCode && $toIsCode) {
            $rows = array_values(array_filter($facts['links'], fn (array $link): bool => in_array($link['from'], $from['modules'], true) && in_array($link['to'], $to['modules'], true)));

            return $rows === [] ? null : ['type' => 'links', 'rows' => $rows];
        }

        if (! $fromIsCode && ! $toIsCode) {
            return null;
        }

        [$code, $outside] = $fromIsCode ? [$from, $to] : [$to, $from];
        $rows = array_values(array_filter($facts['externals'], fn (array $row): bool => in_array($row['module'], $code['modules'], true) && in_array($row['target'], $outside['modules'], true)));

        return $rows === [] ? null : ['type' => 'evidence', 'rows' => $rows];
    }

    /**
     * A trimmed one-line text no longer than $maxLength, or null when it is missing or empty.
     */
    public static function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }
}
