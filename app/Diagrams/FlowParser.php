<?php

namespace App\Diagrams;

use Illuminate\Support\Str;

/**
 * Reads Claude's answer for „Cum funcționează” and keeps only the flows that are safe to draw.
 *
 * The answer must be JSON: {"flows": [{"title", "explanation", "mermaid", "sources": [{"path",
 * "start_line", "end_line"}]}]}. Claude's text is never trusted, so a flow is dropped when:
 *
 * - a field is missing, empty or too long, or the diagram is not a "flowchart TD" / "flowchart TB";
 * - its Mermaid code has anything that can run code, open links, load files or change the page:
 *   click statements (links and callbacks), href, javascript:, HTML tags, %%{init}%% directives,
 *   a front matter block, @{ } shapes (images, icons) or styles (style, classDef, linkStyle);
 * - none of its sources points to real lines of a file that was in the context.
 *
 * The browser draws the code with Mermaid's "strict" security level as a second barrier.
 */
final class FlowParser
{
    /**
     * At most this many flows are kept.
     */
    public const MAX_FLOWS = 3;

    /**
     * At most this many sources are kept for one flow.
     */
    public const MAX_SOURCES = 5;

    /**
     * Mermaid code that must never be drawn: reason => pattern.
     */
    private const FORBIDDEN = [
        'click' => '/^\s*click\b/im',
        'href' => '/\bhref\b/i',
        'callback' => '/\bcall\s+[\w$.]+\s*\(/i',
        'javascript' => '/javascript\s*:/i',
        'init' => '/%%\s*\{/',
        'front matter' => '/^\s*---\s*$/m',
        'html' => '/<\s*\/?\s*[a-z!]/i',
        'shape' => '/@\{/',
        'style' => '/^\s*(style|classDef|linkStyle)\b/im',
    ];

    /**
     * Parse the answer and keep the valid flows, with a unique key each (from the title).
     *
     * @param  array<string, int>  $fileLines  path => number of lines, for the files the context showed
     * @return array{flows: list<array{key: string, title: string, explanation: string, mermaid: string, sources: list<array{path: string, start_line: int, end_line: int}>}>, rejected: list<string>}
     */
    public static function parse(string $answer, array $fileLines): array
    {
        $data = json_decode(self::jsonPart($answer), true);

        if (! is_array($data) || ! is_array($data['flows'] ?? null)) {
            return ['flows' => [], 'rejected' => ['the answer is not JSON with a "flows" list']];
        }

        $flows = [];
        $rejected = [];
        $usedKeys = [];

        foreach (array_slice($data['flows'], 0, self::MAX_FLOWS) as $index => $flow) {
            $reason = is_array($flow) ? self::rejectionReason($flow) : 'not an object';
            $sources = is_array($flow) && $reason === null ? self::validSources($flow['sources'] ?? null, $fileLines) : [];

            if ($reason === null && $sources === []) {
                $reason = 'no valid source lines';
            }

            if ($reason !== null) {
                $rejected[] = 'flow '.($index + 1).": {$reason}";

                continue;
            }

            $title = trim($flow['title']);
            $key = self::uniqueKey($title, $index + 1, $usedKeys);
            $usedKeys[] = $key;

            $flows[] = [
                'key' => $key,
                'title' => $title,
                'explanation' => trim($flow['explanation']),
                'mermaid' => trim($flow['mermaid'])."\n",
                'sources' => $sources,
            ];
        }

        return ['flows' => $flows, 'rejected' => $rejected];
    }

    /**
     * Why a flow cannot be kept, or null when its text fields and its Mermaid code are fine.
     *
     * @param  array<mixed>  $flow
     */
    private static function rejectionReason(array $flow): ?string
    {
        foreach (['title' => 100, 'explanation' => 1500, 'mermaid' => 4000] as $field => $maxLength) {
            if (! is_string($flow[$field] ?? null) || trim($flow[$field]) === '') {
                return "missing {$field}";
            }

            if (mb_strlen($flow[$field]) > $maxLength) {
                return "{$field} too long";
            }
        }

        $mermaid = trim($flow['mermaid']);

        if (preg_match('/\Aflowchart\s+(TD|TB)\s*(\n|\z)/', $mermaid) !== 1) {
            return 'not a "flowchart TD" diagram';
        }

        if (substr_count($mermaid, "\n") > 60) {
            return 'diagram too long';
        }

        foreach (self::FORBIDDEN as $reason => $pattern) {
            if (preg_match($pattern, $mermaid) === 1) {
                return "forbidden Mermaid syntax ({$reason})";
            }
        }

        return null;
    }

    /**
     * The sources that point to real lines of a file the context showed.
     *
     * @param  array<string, int>  $fileLines
     * @return list<array{path: string, start_line: int, end_line: int}>
     */
    private static function validSources(mixed $sources, array $fileLines): array
    {
        if (! is_array($sources)) {
            return [];
        }

        $valid = [];

        foreach ($sources as $source) {
            $path = $source['path'] ?? null;
            $start = $source['start_line'] ?? null;
            $end = $source['end_line'] ?? null;

            if (is_string($path) && isset($fileLines[$path]) && is_int($start) && is_int($end)
                && $start >= 1 && $end >= $start && $end <= $fileLines[$path]) {
                $valid[] = ['path' => $path, 'start_line' => $start, 'end_line' => $end];
            }
        }

        return array_slice($valid, 0, self::MAX_SOURCES);
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
     * A key for the flow from its title ("Adăugarea unui repository" → "adaugarea-unui-repository"),
     * unique among the flows of the answer.
     *
     * @param  list<string>  $usedKeys
     */
    private static function uniqueKey(string $title, int $position, array $usedKeys): string
    {
        $key = rtrim(Str::limit(Str::slug($title), 80, ''), '-') ?: "flux-{$position}";

        return in_array($key, $usedKeys, true) ? "{$key}-{$position}" : $key;
    }
}
