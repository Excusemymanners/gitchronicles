<?php

namespace App\Diagrams;

use App\Enums\DiagramType;

/**
 * Reads Claude's interpretation of the architecture diagrams and keeps only what can be trusted.
 *
 * The answer must be JSON: {"diagrams": {"layers": {"reading": "...", "worth": "..."}, ...}}. Claude
 * writes every module, layer or technology name between backticks, so they can be checked. A reading
 * of one diagram is rejected (and that diagram gets the deterministic reading instead) when:
 *
 * - a field is missing, empty, too long, or has HTML or a line break;
 * - a name between backticks is not in that diagram: not a module, a layer, a class or a technology
 *   shown in it, nor the repository's name;
 * - a number outside backticks is not one the diagram's facts contain (the findings, the arrows'
 *   import counts, the number of modules and arrows, the share of the code the diagram shows).
 */
final class ReadingParser
{
    /**
     * The longest "reading" and "worth" accepted, in characters.
     */
    private const MAX_READING = 900;

    private const MAX_WORTH = 320;

    /**
     * Parse the answer.
     *
     * @param  list<array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}>  $diagrams  the diagrams that were sent
     * @return array{readings: array<string, array{reading: string, worth: string}>, rejected: array<string, string>}
     */
    public static function parse(string $answer, array $diagrams, string $repositoryName): array
    {
        $data = json_decode(self::jsonPart($answer), true);

        if (! is_array($data) || ! is_array($data['diagrams'] ?? null)) {
            return ['readings' => [], 'rejected' => ['*' => 'the answer is not JSON with a "diagrams" object']];
        }

        $readings = [];
        $rejected = [];

        foreach ($diagrams as $diagram) {
            $type = $diagram['type']->value;
            $item = $data['diagrams'][$type] ?? null;

            if (! is_array($item)) {
                $rejected[$type] = 'the diagram has no reading';

                continue;
            }

            $reason = self::problem($item, $diagram, $repositoryName);

            if ($reason === null) {
                $readings[$type] = ['reading' => trim($item['reading']), 'worth' => trim($item['worth'])];
            } else {
                $rejected[$type] = $reason;
            }
        }

        return ['readings' => $readings, 'rejected' => $rejected];
    }

    /**
     * The names a reading of this diagram may put between backticks, in lowercase.
     *
     * @param  array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}  $diagram
     * @return list<string>
     */
    public static function allowedNames(array $diagram, string $repositoryName): array
    {
        $names = [$repositoryName, 'User'];

        foreach (array_values($diagram['meta']['nodes'] ?? []) as $path) {
            $names[] = $path;
            $names[] = basename($path);
        }

        foreach ($diagram['meta']['classes'] ?? [] as $classes) {
            array_push($names, ...$classes);
        }

        foreach ([...($diagram['meta']['technologies'] ?? []), ...($diagram['meta']['externals'] ?? [])] as $technology) {
            $names[] = $technology['name'];
            $names[] = trim(preg_replace('/\s+v?\d[\w.]*$/', '', $technology['name']));
        }

        if ($diagram['type'] === DiagramType::Layers) {
            array_push($names, ...array_values(LayerClassifier::LAYERS));
        }

        return array_values(array_unique(array_map(mb_strtolower(...), $names)));
    }

    /**
     * The numbers a reading of this diagram may use.
     *
     * @param  array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}  $diagram
     * @return list<int>
     */
    public static function allowedNumbers(array $diagram): array
    {
        $meta = $diagram['meta'];
        $numbers = [];

        $findings = $meta['findings'] ?? [];

        array_walk_recursive($findings, function (mixed $value) use (&$numbers): void {
            if (is_int($value)) {
                $numbers[] = $value;
            }
        });

        foreach ($meta['evidence'] ?? [] as $row) {
            $numbers[] = $row['imports'];
        }

        $numbers[] = count($meta['nodes'] ?? []);
        $numbers[] = count($meta['evidence'] ?? []);
        $numbers[] = count($meta['technologies'] ?? []);
        $numbers[] = count(array_unique(array_map(LayerClassifier::layerOf(...), array_values($meta['nodes'] ?? []))));

        $coverage = $meta['findings']['coverage'] ?? null;

        if ($coverage !== null && $coverage['total_lines'] > 0) {
            $numbers[] = (int) round($coverage['shown_lines'] / $coverage['total_lines'] * 100);
        }

        foreach ($meta['technologies'] ?? [] as $technology) {
            preg_match_all('/\d+/', $technology['name'], $found);
            array_push($numbers, ...array_map(intval(...), $found[0]));
        }

        return array_values(array_unique($numbers));
    }

    /**
     * Why a reading is rejected, or null when it is fine.
     *
     * @param  array<string, mixed>  $item
     * @param  array{type: DiagramType, key: string, mermaid: string, meta: array<string, mixed>}  $diagram
     */
    private static function problem(array $item, array $diagram, string $repositoryName): ?string
    {
        foreach (['reading' => self::MAX_READING, 'worth' => self::MAX_WORTH] as $field => $limit) {
            $text = $item[$field] ?? null;

            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $limit) {
                return "the field \"{$field}\" is missing, empty or too long";
            }

            if (preg_match('/[<>\r\n]/', $text) === 1) {
                return "the field \"{$field}\" has HTML or a line break";
            }
        }

        $text = $item['reading'].' '.$item['worth'];
        $allowedNames = self::allowedNames($diagram, $repositoryName);

        preg_match_all('/`([^`]+)`/', $text, $named);

        foreach ($named[1] as $name) {
            if (! in_array(mb_strtolower(trim($name)), $allowedNames, true)) {
                return "\"{$name}\" is not in the diagram";
            }
        }

        $allowedNumbers = self::allowedNumbers($diagram);
        $withoutNames = preg_replace('/`[^`]*`/', ' ', $text);
        preg_match_all('/\d[\d.,]*\d|\d/', $withoutNames, $found);

        foreach ($found[0] as $number) {
            if (! in_array((int) str_replace([',', '.'], '', $number), $allowedNumbers, true)) {
                return "the number {$number} is not in the facts";
            }
        }

        return null;
    }

    /**
     * The JSON object of the answer, also when Claude wrapped it in a ```json block or added a sentence.
     */
    private static function jsonPart(string $answer): string
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');

        return $start === false || $end === false || $end < $start ? '' : substr($answer, $start, $end - $start + 1);
    }
}
