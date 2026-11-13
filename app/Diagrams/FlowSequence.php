<?php

namespace App\Diagrams;

/**
 * One flow ("What happens when someone …?") as a sequence diagram a non-programmer can retell: the people
 * and the parts of the system, named by their role, left to right in the order they first act, and the
 * requests and responses between them in plain words, numbered. The code of every step goes in the step
 * list beside the picture, never in it.
 *
 * - validate(): checks a flow proposed by the text generator against the facts of the code
 *   (SystemFacts): every participant stands for modules or evidence that exist (a person stands for
 *   nobody); a request between two parts of code is backed by a link between their modules in its
 *   direction, a request to or from something outside the code by evidence that the part uses it, and a
 *   person only talks to an interface; a response must answer an earlier request between the same two
 *   participants. Unbacked messages are dropped and counted; when fewer than 60 % (or fewer than three)
 *   survive, the flow is rejected;
 * - mermaid(): the Mermaid `sequenceDiagram` (autonumber, solid requests, dashed responses, activations,
 *   notes, loop / opt / alt boxes), written by us, so no code from the answer is ever drawn as is;
 * - fallback(): the deterministic main flow when there is no valid answer: a request routed to the web
 *   part, which dispatches the work to the background part, which uses the stores, services and tools
 *   the evidence shows, and the page that comes back.
 */
final class FlowSequence
{
    /**
     * The most messages a sequence may keep (a longer story is two diagrams).
     */
    public const MAX_MESSAGES = 14;

    /**
     * The fewest messages a sequence must keep.
     */
    public const MIN_MESSAGES = 3;

    /**
     * Check one proposed flow. $fileLines (path => number of lines) are the files whose lines the context
     * showed, so a step's code reference can be checked; a step with a wrong reference keeps no code link.
     *
     * @param  array<mixed>  $proposed
     * @param  array<string, mixed>  $facts
     * @param  array<string, int>  $fileLines
     * @return array{flow: array<string, mixed>|null, reason: string|null, stats: array{proposed: int, dropped: int}}
     */
    public static function validate(array $proposed, array $facts, array $fileLines): array
    {
        $stats = ['proposed' => 0, 'dropped' => 0];
        $title = SystemMapValidator::text($proposed['title'] ?? null, 100);

        if ($title === null || SystemMapValidator::namesCode($title)) {
            return ['flow' => null, 'reason' => 'missing title or a title that names code', 'stats' => $stats];
        }

        $participants = self::participants($proposed['participants'] ?? null, $facts);
        $messages = [];
        $positions = [];
        $open = [];

        foreach (array_slice(is_array($proposed['messages'] ?? null) ? $proposed['messages'] : [], 0, self::MAX_MESSAGES + 2) as $index => $message) {
            $stats['proposed']++;
            $checked = is_array($message) ? self::message($message, $participants, $facts, $fileLines, $open) : null;

            if ($checked === null) {
                $stats['dropped']++;

                continue;
            }

            $positions[$index + 1] = count($messages) + 1;
            $messages[] = $checked;
        }

        $reason = match (true) {
            count($messages) < self::MIN_MESSAGES => 'fewer than '.self::MIN_MESSAGES.' messages are backed by the code',
            count($messages) / max(1, $stats['proposed']) < SystemMapValidator::MIN_SURVIVING_SHARE => 'fewer than 60 % of the messages are backed by the code',
            default => null,
        };

        if ($reason !== null) {
            return ['flow' => null, 'reason' => $reason, 'stats' => $stats];
        }

        $messages = array_slice($messages, 0, self::MAX_MESSAGES);
        $acting = array_flip(array_merge(...array_map(fn (array $message): array => [$message['from'], $message['to']], $messages)));
        $ordered = [];

        // Left to right in the order they first act.
        foreach ($messages as $message) {
            foreach ([$message['from'], $message['to']] as $id) {
                $ordered[$id] ??= $participants[$id];
            }
        }

        return [
            'flow' => [
                'title' => $title,
                'explanation' => SystemMapValidator::text($proposed['explanation'] ?? null, 900) ?? '',
                'main' => ($proposed['main'] ?? false) === true,
                'participants' => array_values(array_intersect_key($ordered, $acting)),
                'messages' => $messages,
                'notes' => self::notes($proposed['notes'] ?? null, $positions, $acting, count($messages)),
                'groups' => self::groups($proposed['groups'] ?? null, $positions, count($messages)),
            ],
            'reason' => null,
            'stats' => $stats,
        ];
    }

    /**
     * The Mermaid code of a checked flow.
     *
     * @param  array{participants: list<array{id: string, label: string, kind: string, modules: list<string>}>, messages: list<array{from: string, to: string, text: string, type: string}>, notes: list<array{after: int, over: list<string>, text: string}>, groups: list<array{type: string, label: string, from: int, to: int, else_at?: int|null, else_label?: string|null}>}  $flow
     */
    public static function mermaid(array $flow): string
    {
        $lines = ['sequenceDiagram', '  autonumber'];

        foreach ($flow['participants'] as $participant) {
            $id = self::participantId($participant['id']);
            $label = MermaidText::escape($participant['label'], 40);
            $lines[] = match (true) {
                $participant['kind'] === 'person' => "  actor {$id} as {$label}",
                $participant['kind'] === 'data' => "  participant {$id}@{ \"type\": \"".match (true) {
                    in_array('store:database', $participant['modules'], true) => 'database',
                    in_array('store:queue', $participant['modules'], true) => 'queue',
                    in_array('store:files', $participant['modules'], true) => 'collections',
                    default => 'database',
                }."\", \"alias\": \"{$label}\" }",
                default => "  participant {$id} as {$label}",
            };
        }

        $activations = self::activations($flow['messages']);
        $groupsAt = [];

        foreach ($flow['groups'] as $group) {
            $groupsAt[$group['from']]['open'][] = $group;
            $groupsAt[$group['to']]['close'] = ($groupsAt[$group['to']]['close'] ?? 0) + 1;

            if (($group['else_at'] ?? null) !== null) {
                $groupsAt[$group['else_at']]['else'] = $group['else_label'] ?? '';
            }
        }

        foreach ($flow['messages'] as $index => $message) {
            $number = $index + 1;

            foreach ($groupsAt[$number]['open'] ?? [] as $group) {
                $lines[] = "  {$group['type']} ".MermaidText::escape($group['label'], 50);
            }

            if (isset($groupsAt[$number]['else'])) {
                $lines[] = '  else '.MermaidText::escape($groupsAt[$number]['else'], 50);
            }

            $arrow = $message['type'] === 'response' ? '-->>' : '->>';
            $lines[] = '  '.self::participantId($message['from']).$arrow.($activations[$index] ?? '').self::participantId($message['to']).': '.MermaidText::escape($message['text'], 70);

            foreach ($flow['notes'] as $note) {
                if ($note['after'] === $number) {
                    $lines[] = '  Note over '.implode(',', array_map(self::participantId(...), $note['over'])).': '.MermaidText::escape($note['text'], 90);
                }
            }

            for ($i = 0; $i < ($groupsAt[$number]['close'] ?? 0); $i++) {
                $lines[] = '  end';
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The deterministic main flow: a person's request routed to the web part, the work it dispatches to the
     * background part, what that part uses outside the code, and the answer that comes back. Null when the
     * code shows no route that leads to dispatched work (a library, a command-line tool).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    public static function fallback(array $facts, string $repositoryName): ?array
    {
        $route = self::strongest($facts['links'], 'route');

        if ($route === null) {
            return null;
        }

        // The work is dispatched by the routed part, or by a part it calls (a controller calling an action).
        $near = [$route['to'], ...array_column(array_filter($facts['links'], fn (array $link): bool => $link['from'] === $route['to']), 'to')];
        $dispatch = self::strongest(array_filter($facts['links'], fn (array $link): bool => in_array($link['from'], $near, true)), 'dispatch');

        if ($dispatch === null) {
            return null;
        }

        $render = self::strongest(array_filter($facts['links'], fn (array $link): bool => $link['from'] === $route['to']), 'render');
        $web = array_values(array_unique([$route['to'], $dispatch['from']]));

        // The background part is the dispatched code and the code it calls directly that reaches outside.
        $helpers = array_column(array_filter($facts['links'], fn (array $link): bool => $link['from'] === $dispatch['to']), 'to');
        $jobModules = [$dispatch['to'], ...array_values(array_unique(array_filter($helpers, fn (string $module): bool => ! in_array($module, $web, true)
            && collect($facts['externals'])->contains(fn (array $row): bool => $row['module'] === $module && ! str_starts_with($row['target'], 'store:')))))];
        $participants = [
            'visitor' => ['id' => 'visitor', 'label' => 'Visitor', 'kind' => 'person', 'modules' => []],
            'web' => ['id' => 'web', 'label' => 'Web app', 'kind' => 'interface', 'modules' => $web],
            'jobs' => ['id' => 'jobs', 'label' => 'Background work', 'kind' => 'worker', 'modules' => $jobModules],
        ];
        $messages = [
            ['from' => 'visitor', 'to' => 'web', 'text' => 'sends a request', 'type' => 'request', 'step' => ['link' => $route, 'kind' => 'route']],
            ['from' => 'web', 'to' => 'jobs', 'text' => 'dispatches the work', 'type' => 'request', 'step' => ['link' => $dispatch, 'kind' => 'dispatch']],
        ];

        // What the work uses outside the code: programs and services first, then the stores (not the queue it came from).
        $order = ['tool' => 0, 'external' => 1, 'data' => 2];
        $used = [];

        foreach ($facts['externals'] as $row) {
            if (in_array($row['module'], $jobModules, true) && $row['target'] !== 'store:queue') {
                $used[$row['target']] = isset($used[$row['target']]) && $used[$row['target']]['count'] >= $row['count'] ? $used[$row['target']] : $row;
            }
        }

        uasort($used, fn (array $a, array $b): int => [$order[$facts['targets'][$a['target']]['type']], -$a['count']] <=> [$order[$facts['targets'][$b['target']]['type']], -$b['count']]);

        foreach (array_slice($used, 0, 4) as $row) {
            $id = 'out'.count($participants);
            $participants[$id] = ['id' => $id, 'label' => $facts['targets'][$row['target']]['name'], 'kind' => $facts['targets'][$row['target']]['type'], 'modules' => [$row['target']]];
            $text = match ($row['kind']) {
                'runs' => 'starts the program',
                'calls' => 'sends a request',
                'queues' => 'queues more work',
                default => 'reads and writes data',
            };
            $messages[] = ['from' => 'jobs', 'to' => $id, 'text' => $text, 'type' => 'request', 'step' => ['evidence' => $row]];
        }

        if ($render !== null) {
            $participants['pages'] = ['id' => 'pages', 'label' => 'Web pages', 'kind' => 'interface', 'modules' => [$render['to']]];
            $messages[] = ['from' => 'web', 'to' => 'pages', 'text' => 'renders the page', 'type' => 'request', 'step' => ['link' => $render, 'kind' => 'render']];
        }

        $messages[] = ['from' => 'web', 'to' => 'visitor', 'text' => 'returns the answer', 'type' => 'response', 'step' => null];
        $acting = [];

        foreach ($messages as $message) {
            $acting[$message['from']] ??= $participants[$message['from']];
            $acting[$message['to']] ??= $participants[$message['to']];
        }

        $isQueued = collect($facts['externals'])->contains(fn (array $row): bool => $row['module'] === $dispatch['to'] && $row['target'] === 'store:queue');

        return [
            'title' => 'What happens when someone uses '.$repositoryName,
            'explanation' => 'Drawn from the links found in the code: the route that receives the request, the work it dispatches and what that work uses. AI did not write this flow.',
            'main' => true,
            'participants' => array_values($acting),
            'messages' => array_map(fn (array $message): array => [...$message, 'does' => null, 'code' => null], $messages),
            'notes' => $isQueued ? [['after' => 2, 'over' => ['web', 'jobs'], 'text' => 'the work waits in a queue and runs in the background']] : [],
            'groups' => [],
        ];
    }

    /**
     * The Mermaid id of a participant.
     */
    public static function participantId(string $id): string
    {
        return 'p_'.$id;
    }

    /**
     * The checked participants, by id: a person, or a part of code or outside the code (SystemMapValidator).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, array{id: string, label: string, kind: string, modules: list<string>}>
     */
    private static function participants(mixed $proposed, array $facts): array
    {
        $participants = [];
        $used = [];
        $stats = ['fixed' => 0];

        foreach (array_slice(is_array($proposed) ? $proposed : [], 0, 12) as $participant) {
            if (! is_array($participant)) {
                continue;
            }

            if (($participant['kind'] ?? null) === 'person') {
                $id = strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) ($participant['id'] ?? '')));
                $label = SystemMapValidator::text($participant['label'] ?? null, 30);

                if ($id !== '' && $label !== null && ! SystemMapValidator::namesCode($label)) {
                    $participants[$id] = ['id' => $id, 'label' => $label, 'kind' => 'person', 'modules' => []];
                }

                continue;
            }

            $part = SystemMapValidator::part($participant, $facts, $used, $stats);

            if ($part !== null && ! isset($participants[$part['id']])) {
                $participants[$part['id']] = ['id' => $part['id'], 'label' => $part['label'], 'kind' => $part['kind'], 'modules' => $part['modules']];
            }
        }

        return $participants;
    }

    /**
     * One checked message, or null. $open holds the requests still waiting for their response ("from>to" => count).
     *
     * @param  array<mixed>  $message
     * @param  array<string, array{id: string, kind: string, modules: list<string>}>  $participants
     * @param  array<string, mixed>  $facts
     * @param  array<string, int>  $fileLines
     * @param  array<string, int>  $open
     * @return array{from: string, to: string, text: string, type: string, does: string|null, code: array{path: string, start_line: int, end_line: int}|null}|null
     */
    private static function message(array $message, array $participants, array $facts, array $fileLines, array &$open): ?array
    {
        $from = strtolower((string) ($message['from'] ?? ''));
        $to = strtolower((string) ($message['to'] ?? ''));
        $text = SystemMapValidator::text($message['text'] ?? null, 70);
        $type = ($message['type'] ?? 'request') === 'response' ? 'response' : 'request';

        if (! isset($participants[$from], $participants[$to]) || $from === $to || $text === null || str_word_count($text) > 12 || SystemMapValidator::namesCode($text)) {
            return null;
        }

        if ($type === 'response') {
            // A response answers an earlier request between the same two participants.
            if (($open["{$to}>{$from}"] ?? 0) === 0) {
                return null;
            }

            $open["{$to}>{$from}"]--;
        } elseif (! self::isBacked($participants[$from], $participants[$to], $facts)) {
            return null;
        } else {
            $open["{$from}>{$to}"] = ($open["{$from}>{$to}"] ?? 0) + 1;
        }

        $does = SystemMapValidator::text($message['does'] ?? null, 140);

        return ['from' => $from, 'to' => $to, 'text' => $text, 'type' => $type, 'does' => $does, 'code' => self::code($message['code'] ?? null, $fileLines)];
    }

    /**
     * Whether a request between two participants is backed: a person talks to an interface; parts of
     * code by a link in the request's direction; code and something outside it by evidence.
     *
     * @param  array{kind: string, modules: list<string>}  $from
     * @param  array{kind: string, modules: list<string>}  $to
     * @param  array<string, mixed>  $facts
     */
    private static function isBacked(array $from, array $to, array $facts): bool
    {
        if ($from['kind'] === 'person' || $to['kind'] === 'person') {
            return ($from['kind'] === 'person' ? $to['kind'] : $from['kind']) === 'interface';
        }

        return SystemMapValidator::backing($from, $to, $facts) !== null;
    }

    /**
     * A step's code reference when it points to real lines of a file the context showed, else null.
     *
     * @param  array<string, int>  $fileLines
     * @return array{path: string, start_line: int, end_line: int}|null
     */
    private static function code(mixed $code, array $fileLines): ?array
    {
        $path = is_array($code) ? ($code['path'] ?? null) : null;
        $start = is_array($code) ? ($code['start_line'] ?? null) : null;
        $end = is_array($code) ? ($code['end_line'] ?? $start) : null;

        return is_string($path) && isset($fileLines[$path]) && is_int($start) && is_int($end) && $start >= 1 && $end >= $start && $end <= $fileLines[$path]
            ? ['path' => $path, 'start_line' => $start, 'end_line' => $end]
            : null;
    }

    /**
     * The notes at a hand-off, moved to the numbering of the kept messages.
     *
     * @param  array<int, int>  $positions  proposed message number => kept message number
     * @param  array<string, int>  $acting
     * @return list<array{after: int, over: list<string>, text: string}>
     */
    private static function notes(mixed $proposed, array $positions, array $acting, int $count): array
    {
        $notes = [];

        foreach (array_slice(is_array($proposed) ? $proposed : [], 0, 3) as $note) {
            $after = is_array($note) && is_int($note['after'] ?? null) ? ($positions[$note['after']] ?? null) : null;
            $over = array_values(array_unique(array_filter(is_array($note['over'] ?? null) ? $note['over'] : [], fn (mixed $id): bool => is_string($id) && isset($acting[strtolower($id)]))));
            $text = SystemMapValidator::text($note['text'] ?? null, 90);

            if ($after !== null && $after <= $count && $over !== [] && $text !== null && ! SystemMapValidator::namesCode($text)) {
                $notes[] = ['after' => $after, 'over' => array_map('strtolower', array_slice($over, 0, 2)), 'text' => $text];
            }
        }

        return $notes;
    }

    /**
     * The loop / opt / alt boxes, moved to the numbering of the kept messages; boxes may not overlap.
     *
     * @param  array<int, int>  $positions
     * @return list<array{type: string, label: string, from: int, to: int, else_at: int|null, else_label: string|null}>
     */
    private static function groups(mixed $proposed, array $positions, int $count): array
    {
        $groups = [];
        $lastEnd = 0;

        foreach (array_slice(is_array($proposed) ? $proposed : [], 0, 3) as $group) {
            if (! is_array($group) || ! in_array($group['type'] ?? null, ['loop', 'opt', 'alt'], true)) {
                continue;
            }

            $from = self::keptFrom($group['from'] ?? null, $positions, true);
            $to = self::keptFrom($group['to'] ?? null, $positions, false);
            $label = SystemMapValidator::text($group['label'] ?? null, 50);
            $elseAt = $group['type'] === 'alt' ? self::keptFrom($group['else_at'] ?? null, $positions, true) : null;

            if ($from === null || $to === null || $from > $to || $to > $count || $from <= $lastEnd || $label === null || SystemMapValidator::namesCode($label)) {
                continue;
            }

            $elseAt = $elseAt !== null && $elseAt > $from && $elseAt <= $to ? $elseAt : null;
            $groups[] = ['type' => $group['type'], 'label' => $label, 'from' => $from, 'to' => $to, 'else_at' => $elseAt, 'else_label' => $elseAt === null ? null : (SystemMapValidator::text($group['else_label'] ?? null, 50) ?? 'otherwise')];
            $lastEnd = $to;
        }

        return $groups;
    }

    /**
     * The kept message number for a proposed one: the same message, or the next kept one (for the start of a
     * box) or the previous kept one (for its end) when it was dropped.
     *
     * @param  array<int, int>  $positions
     */
    private static function keptFrom(mixed $number, array $positions, bool $forward): ?int
    {
        if (! is_int($number)) {
            return null;
        }

        for ($i = $number; $forward ? $i <= $number + 14 : $i >= 1; $forward ? $i++ : $i--) {
            if (isset($positions[$i])) {
                return $positions[$i];
            }
        }

        return null;
    }

    /**
     * The activation marks of the messages: a request that gets its response later starts an activation
     * ("+") on the participant that works, and that response ends it ("-").
     *
     * @param  list<array{from: string, to: string, type: string}>  $messages
     * @return array<int, string> message index => "+" or "-"
     */
    private static function activations(array $messages): array
    {
        $marks = [];
        $waiting = [];

        foreach ($messages as $index => $message) {
            if ($message['type'] === 'request') {
                $waiting["{$message['from']}>{$message['to']}"][] = $index;
            } elseif (($waiting["{$message['to']}>{$message['from']}"] ?? []) !== []) {
                $marks[array_pop($waiting["{$message['to']}>{$message['from']}"])] = '+';
                $marks[$index] = '-';
            }
        }

        return $marks;
    }

    /**
     * The strongest link of a kind, or null.
     *
     * @param  array<int, array{from: string, to: string, kinds: array<string, int>}>  $links
     * @return array{from: string, to: string, kinds: array<string, int>}|null
     */
    private static function strongest(array $links, string $kind): ?array
    {
        $best = null;

        foreach ($links as $link) {
            if (($link['kinds'][$kind] ?? 0) > ($best['kinds'][$kind] ?? 0)) {
                $best = $link;
            }
        }

        return $best;
    }
}
