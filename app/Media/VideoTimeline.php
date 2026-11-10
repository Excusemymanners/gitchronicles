<?php

namespace App\Media;

/**
 * When each scene of the video starts and how long it lasts, fitted to its voice clip: the voice starts
 * a moment after the cut (VOICE_LEAD) and the scene ends a moment after the voice (VOICE_TAIL), but never
 * before the scene's own animation had time to play (MIN_SECONDS). A scene without a voice clip lasts
 * as long as reading its text takes.
 *
 * If the whole video would be longer than MAX_TOTAL_SECONDS and there are three „De ce e special” scenes,
 * the third one is left out (the most special come first). Times are rounded to whole frames (30 per second).
 */
final class VideoTimeline
{
    /**
     * Frames per second of the video.
     */
    public const FPS = 30;

    /**
     * The video should stay this short, like a reel.
     */
    public const MAX_TOTAL_SECONDS = 35.0;

    /**
     * How long after the cut the voice starts, and how long the scene stays after the voice.
     */
    public const VOICE_LEAD = 0.3;

    public const VOICE_TAIL = 0.45;

    /**
     * The shortest scene of each kind: the time its entrance animation needs to be seen.
     *
     * @var array<string, float>
     */
    public const MIN_SECONDS = [
        'hook' => 3.0,
        'problem' => 3.0,
        'feature' => 2.8,
        'start' => 4.2,
        'proof' => 3.2,
        'cta' => 3.8,
    ];

    /**
     * Place the scenes on the timeline.
     *
     * @param  list<array<string, mixed>>  $scenes  the script's scenes
     * @param  list<float|null>  $voiceSeconds  the length of each scene's voice clip (null = no voice)
     * @return array{scenes: list<array<string, mixed>>, total: float} the kept scenes with start, duration, voice_at and voice_seconds
     */
    public static function plan(array $scenes, array $voiceSeconds): array
    {
        $planned = self::place($scenes, $voiceSeconds);

        if ($planned['total'] > self::MAX_TOTAL_SECONDS) {
            $features = array_keys(array_filter($scenes, fn (array $scene): bool => $scene['key'] === 'feature'));

            if (count($features) === 3) {
                $third = $features[2];
                unset($scenes[$third], $voiceSeconds[$third]);
                $scenes = self::renumberFeatures(array_values($scenes));
                $planned = self::place($scenes, array_values($voiceSeconds));
            }
        }

        return $planned;
    }

    /**
     * One pass: every scene after the previous one.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<float|null>  $voiceSeconds
     * @return array{scenes: list<array<string, mixed>>, total: float}
     */
    private static function place(array $scenes, array $voiceSeconds): array
    {
        $start = 0.0;
        $placed = [];

        foreach ($scenes as $position => $scene) {
            $voice = $voiceSeconds[$position] ?? null;
            $minimum = self::MIN_SECONDS[$scene['key']] ?? 3.0;
            $duration = $voice === null
                ? self::readingSeconds($scene, $minimum)
                : max($minimum, self::VOICE_LEAD + $voice + self::VOICE_TAIL);
            $duration = self::frames($duration);

            $placed[] = [
                ...$scene,
                'start' => self::frames($start),
                'duration' => $duration,
                'voice_at' => $voice === null ? null : self::frames($start + self::VOICE_LEAD),
                'voice_seconds' => $voice === null ? null : round($voice, 3),
            ];

            $start += $duration;
        }

        return ['scenes' => $placed, 'total' => self::frames($start)];
    }

    /**
     * Without a voice: about a second to notice the scene, then the time to read its texts.
     *
     * @param  array<string, mixed>  $scene
     */
    private static function readingSeconds(array $scene, float $minimum): float
    {
        $characters = mb_strlen(implode(' ', array_filter([
            $scene['title'] ?? null, $scene['text'] ?? null, $scene['command'] ?? null, $scene['note'] ?? null,
        ], 'is_string')));

        return min(6.5, max($minimum, 1.2 + 0.055 * $characters));
    }

    /**
     * After a feature was left out, the others are numbered again: 1/2, 2/2.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    private static function renumberFeatures(array $scenes): array
    {
        $count = count(array_filter($scenes, fn (array $scene): bool => $scene['key'] === 'feature'));
        $index = 0;

        return array_map(function (array $scene) use ($count, &$index): array {
            if ($scene['key'] !== 'feature') {
                return $scene;
            }

            $index++;

            return [...$scene, 'index' => $index, 'count' => $count];
        }, $scenes);
    }

    /**
     * Seconds rounded to whole frames.
     */
    private static function frames(float $seconds): float
    {
        return round(round($seconds * self::FPS) / self::FPS, 3);
    }
}
