<?php

namespace App\Media;

/**
 * Decides what the camera shows in every scene of the video: the "shots". A shot is a real element of
 * the photographed GitHub page (PageCapture) — its box in CSS pixels of the page — and the moment of
 * the scene when the camera goes to it. The composition scrolls the page to the box, zooms a little and
 * draws a frame around it.
 *
 *   opening          the repository's name, then its description (or the README text the line quotes),
 *                    then the logo the README opens with, when it has one
 *   problem          the README paragraph the line quotes
 *   feature          the section's heading, the quoted paragraph, then the section's image when it has one
 *   README tour      each named heading in turn
 *   how it is built  the file list, then the language bar
 *   numbers          the star counter and the fork counter while the voice says them, the latest commit,
 *                    then the contributors while it speaks about the people
 *   how to start     the exact line of the command in its code block
 *   closing          the top of the page again
 *
 * Only what is truly on the page gets a shot: an element the photo does not have is skipped, and a scene
 * left without any shot shows the top of the README without a frame. Without a photo there are no shots
 * at all (the video then uses its typographic scenes).
 *
 * "at" is the moment as a share of the scene's narration (0 = its start); "sentence" ties a shot to the
 * start of one sentence instead, where the sentences are known in advance (the numbers scene).
 */
final class SceneDirector
{
    /**
     * How much of the file list is framed, in CSS pixels: about five rows.
     */
    public const FILE_LIST_HEIGHT = 205;

    /**
     * Add "shots" to every scene.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    public static function direct(array $scenes, PageCapture $capture): array
    {
        if (! $capture->isAvailable()) {
            return array_map(fn (array $scene): array => [...$scene, 'shots' => []], $scenes);
        }

        $previousHeading = null;

        return array_map(function (array $scene) use ($capture, &$previousHeading): array {
            $shots = array_values(array_filter(self::shots($scene, $capture)));

            // Two features of the same README section: the camera does not go back to the heading it just showed.
            if (count($shots) > 1 && $shots[0]['kind'] === 'heading' && ! isset($scene['headings']) && ($scene['heading'] ?? null) === $previousHeading) {
                array_shift($shots);
                $shots[0]['at'] = 0.0;
            }

            $previousHeading = $scene['heading'] ?? null;

            if ($shots === []) {
                $shots = array_values(array_filter([self::fallback($scene, $capture)]));
            }

            return [...$scene, 'shots' => $shots];
        }, $scenes);
    }

    /**
     * The shots of one scene, by its kind (missing elements are null).
     *
     * @param  array<string, mixed>  $scene
     * @return list<array<string, mixed>|null>
     */
    private static function shots(array $scene, PageCapture $capture): array
    {
        $quote = $capture->locate($scene['quote'] ?? null);
        $heading = $capture->heading($scene['heading'] ?? null);
        $counters = self::union($capture->landmark('stars'), $capture->landmark('forks'));

        return match ($scene['key']) {
            'hook' => [
                self::shot('name', $capture->landmark('name'), 0.0),
                // The description comes with the second sentence when the opening has two (name, then description).
                self::shot('about', $capture->landmark('about') ?? $quote, 0.0, sentence: 1),
                // The repository's own logo, when its README opens with one: the face of the project.
                self::shot('logo', $capture->landmark('logo'), 0.0, sentence: 2),
            ],
            'problem' => [
                self::shot('quote', $quote, 0.0),
            ],
            'feature' => isset($scene['headings'])
                ? array_map(
                    fn (string $text): ?array => self::shot('heading', $capture->heading($text), self::spokenAt($text, (string) ($scene['narration'] ?? ''))),
                    $scene['headings'],
                )
                : [
                    self::shot('heading', $heading, 0.0),
                    self::shot('quote', $quote, $heading === null ? 0.0 : 0.22),
                    self::shot('image', $heading === null ? null : $capture->imageUnder($heading), 0.72),
                ],
            'built' => [
                self::shot('files', self::firstRows($capture->landmark('files')), 0.0),
                self::shot('languages', $capture->landmark('languages'), 0.55),
            ],
            'proof' => [
                ($scene['stars'] ?? null) === null ? null : self::shot('stars', $capture->landmark('stars'), 0.0, sentence: 0, view: $counters),
                ($scene['has_forks'] ?? false) ? self::shot('forks', $capture->landmark('forks'), 0.5, sentence: 0, view: $counters) : null,
                // The history is told in two sentences: the commits and the latest one (the latest-commit row),
                // then the people (the contributors, near the end of the page).
                ($scene['has_history'] ?? false) ? self::shot('commit', self::row($capture->landmark('commit'), $capture), 0.0, sentence: ($scene['stars'] ?? null) === null ? 0 : 1) : null,
                ($scene['has_history'] ?? false) ? self::shot('contributors', $capture->landmark('contributors'), 0.0, sentence: ($scene['stars'] ?? null) === null ? 1 : 2) : null,
            ],
            'start' => [
                self::shot('command', $quote, 0.0),
            ],
            'cta' => [
                self::shot('top', $capture->landmark('name'), 0.0, framed: false),
            ],
            default => [],
        };
    }

    /**
     * The shot of a scene whose own elements are not on the page: the top of the README (the top of the
     * page for the opening), without a frame.
     *
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>|null
     */
    private static function fallback(array $scene, PageCapture $capture): ?array
    {
        $readme = $capture->landmark('readme');

        if ($readme === null || $scene['key'] === 'hook') {
            return self::shot('top', $capture->landmark('name'), 0.0, framed: false);
        }

        return self::shot('page', ['x' => $readme['x'], 'y' => $readme['y'], 'w' => $readme['w'], 'h' => 320], 0.0, framed: false);
    }

    /**
     * One shot, or null when the page does not have the element.
     *
     * @param  array{x: int, y: int, w: int, h: int}|null  $box  the element the frame is drawn around
     * @param  array{x: int, y: int, w: int, h: int}|null  $view  what the camera looks at, when it is more than the element
     * @return array{kind: string, x: int, y: int, w: int, h: int, at: float, sentence: int|null, framed: bool, view: array{x: int, y: int, w: int, h: int}|null}|null
     */
    private static function shot(string $kind, ?array $box, float $at, ?int $sentence = null, bool $framed = true, ?array $view = null): ?array
    {
        if ($box === null || $box['w'] <= 0 || $box['h'] <= 0) {
            return null;
        }

        return [
            'kind' => $kind, 'x' => (int) $box['x'], 'y' => (int) $box['y'], 'w' => (int) $box['w'], 'h' => (int) $box['h'],
            'at' => $at, 'sentence' => $sentence, 'framed' => $framed, 'view' => $view,
        ];
    }

    /**
     * The smallest box that holds two elements (the star and the fork counters stand on one row: the
     * camera shows the whole row and only the frame moves from one to the other). Null unless both exist
     * on the same row.
     *
     * @param  array{x: int, y: int, w: int, h: int}|null  $first
     * @param  array{x: int, y: int, w: int, h: int}|null  $second
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    private static function union(?array $first, ?array $second): ?array
    {
        if ($first === null || $second === null || abs($first['y'] - $second['y']) > 4) {
            return null;
        }

        $left = min($first['x'], $second['x']);
        $top = min($first['y'], $second['y']);

        return [
            'x' => $left,
            'y' => $top,
            'w' => max($first['x'] + $first['w'], $second['x'] + $second['w']) - $left,
            'h' => max($first['y'] + $first['h'], $second['y'] + $second['h']) - $top,
        ];
    }

    /**
     * When a name is said in a narration, as a share of the narration (0 = at its start): where the name
     * stands among the narration's characters. The camera reaches a README heading when the voice names it.
     */
    private static function spokenAt(string $name, string $narration): float
    {
        $position = mb_stripos($narration, $name);

        return $position === false || $narration === '' ? 0.0 : round($position / mb_strlen($narration), 3);
    }

    /**
     * The top of the file list: the whole list is taller than the screen, and its first rows (the main
     * folders) are what "how it is built" shows.
     *
     * @param  array{x: int, y: int, w: int, h: int}|null  $files
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    private static function firstRows(?array $files): ?array
    {
        return $files === null ? null : [...$files, 'h' => min($files['h'], self::FILE_LIST_HEIGHT)];
    }

    /**
     * The latest-commit line widened to the whole row of the file list, so the frame holds the author
     * and the date together.
     *
     * @param  array{x: int, y: int, w: int, h: int}|null  $commit
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    private static function row(?array $commit, PageCapture $capture): ?array
    {
        $files = $capture->landmark('files');

        if ($commit === null || $files === null) {
            return $commit;
        }

        return ['x' => $files['x'], 'y' => $commit['y'] - 12, 'w' => $files['w'], 'h' => $commit['h'] + 24];
    }
}
