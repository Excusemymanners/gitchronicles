<?php

namespace App\Diagrams;

use Illuminate\Support\Str;

/**
 * Reads the flows the text generator wrote (strict JSON, see FlowDiagramWriter) and keeps the valid ones,
 * checked by FlowSequence against the facts of the code. The main flow (the end-to-end path of what the
 * project exists to do) always comes first. Each kept flow gets a unique key made from its title, its
 * Mermaid sequence diagram (written by us, never taken from the answer) and the code references of its
 * steps as "sources".
 */
final class FlowParser
{
    /**
     * At most this many flows are kept.
     */
    public const MAX_FLOWS = 3;

    /**
     * At most this many code references are kept as the "sources" of a flow.
     */
    public const MAX_SOURCES = 8;

    /**
     * Parse the answer and keep the valid flows, the main one first.
     *
     * @param  array<string, int>  $fileLines  path => number of lines, for the files the context showed
     * @param  array<string, mixed>  $facts  SystemFacts
     * @return array{flows: list<array<string, mixed>>, rejected: list<string>, stats: array{proposed: int, dropped: int}}
     */
    public static function parse(string $answer, array $fileLines, array $facts): array
    {
        $data = json_decode(self::jsonPart($answer), true);
        $stats = ['proposed' => 0, 'dropped' => 0];

        // Claude sometimes closes the list too early ({"flows":[{…}]},{…}): then the flows are read one by one.
        if (! is_array($data)) {
            $data = ['flows' => self::flowObjects($answer)];
        }

        if (! is_array($data) || ! is_array($data['flows'] ?? null) || $data['flows'] === []) {
            return ['flows' => [], 'rejected' => ['the answer is not JSON with a "flows" list'], 'stats' => $stats];
        }

        $flows = [];
        $rejected = [];

        foreach (array_slice($data['flows'], 0, self::MAX_FLOWS) as $index => $proposed) {
            $checked = is_array($proposed) ? FlowSequence::validate($proposed, $facts, $fileLines) : ['flow' => null, 'reason' => 'not an object', 'stats' => ['proposed' => 0, 'dropped' => 0]];
            $stats['proposed'] += $checked['stats']['proposed'];
            $stats['dropped'] += $checked['stats']['dropped'];

            if ($checked['flow'] === null) {
                $rejected[] = 'flow '.($index + 1).": {$checked['reason']}";

                continue;
            }

            $flows[] = $checked['flow'];
        }

        // The main flow first (the one marked "main", else the first one); only one flow is the main one.
        $main = array_key_first(array_filter($flows, fn (array $flow): bool => $flow['main'])) ?? 0;
        $flows = $flows === [] ? [] : [$flows[$main], ...array_values(array_diff_key($flows, [$main => true]))];

        foreach ($flows as $position => &$flow) {
            $flow['main'] = $position === 0;
        }

        unset($flow);

        return ['flows' => self::finish($flows), 'rejected' => $rejected, 'stats' => $stats];
    }

    /**
     * Give each flow its key, its Mermaid code and its sources (the code references of its steps).
     *
     * @param  list<array<string, mixed>>  $flows
     * @return list<array<string, mixed>>
     */
    public static function finish(array $flows): array
    {
        $usedKeys = [];

        foreach ($flows as $position => &$flow) {
            $flow['key'] = self::uniqueKey($flow['title'], $position + 1, $usedKeys);
            $usedKeys[] = $flow['key'];
            $flow['mermaid'] = FlowSequence::mermaid($flow);
            $flow['sources'] = array_slice(array_values(array_unique(array_filter(array_column($flow['messages'], 'code')), SORT_REGULAR)), 0, self::MAX_SOURCES);
        }

        unset($flow);

        return $flows;
    }

    /**
     * Every JSON object of the answer that starts with a "title" (a flow), read on its own.
     *
     * @return list<array<mixed>>
     */
    private static function flowObjects(string $answer): array
    {
        $flows = [];
        $offset = 0;

        while (($start = strpos($answer, '{"title"', $offset)) !== false) {
            $depth = 0;
            $inString = false;

            for ($i = $start; $i < strlen($answer); $i++) {
                $character = $answer[$i];

                if ($inString) {
                    $i += $character === '\\' ? 1 : 0;
                    $inString = $character !== '"';
                } elseif ($character === '"') {
                    $inString = true;
                } elseif ($character === '{' || $character === '}') {
                    $depth += $character === '{' ? 1 : -1;

                    if ($depth === 0) {
                        break;
                    }
                }
            }

            $flow = json_decode(substr($answer, $start, $i - $start + 1), true);

            if (is_array($flow)) {
                $flows[] = $flow;
            }

            $offset = $i + 1;
        }

        return $flows;
    }

    /**
     * The JSON object inside the answer: Claude sometimes wraps it in ```json fences or a sentence.
     */
    private static function jsonPart(string $answer): string
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');

        return $start === false || $end === false || $end < $start ? '' : substr($answer, $start, $end - $start + 1);
    }

    /**
     * A key for the flow from its title ("What happens when someone adds a repository" →
     * "what-happens-when-someone-adds-a-repository"), unique among the flows of the answer.
     *
     * @param  list<string>  $usedKeys
     */
    private static function uniqueKey(string $title, int $position, array $usedKeys): string
    {
        $key = rtrim(Str::limit(Str::slug($title), 80, ''), '-') ?: "flow-{$position}";

        return in_array($key, $usedKeys, true) ? "{$key}-{$position}" : $key;
    }
}
