<?php

namespace App\Media;

/**
 * When each scene of the video starts and how long it lasts, fitted to its voice: the voice starts a
 * moment after the scene (VOICE_LEAD), its sentences follow one another with a short breath between them
 * (SENTENCE_GAP), and the scene ends a moment after the voice (VOICE_TAIL) — but never before the scene
 * had time to be seen (MIN_SECONDS). A scene without a voice lasts as long as reading its text takes.
 *
 * Every scene also gets, in seconds of the video:
 * - "sentences": each sentence with its start and end (exact: one voice clip per sentence);
 * - "cues": the caption phrases with their timed words (Captions::cues());
 * - the "time" of each camera shot (SceneDirector), counted from the start of the scene. The camera
 *   leaves SHOT_LEAD before the words it illustrates, so it has arrived when they are said.
 *
 * If the whole video would be longer than the limit (config gitchronicle.media.max_seconds), the last
 * „Ce face” scenes are left out one by one, down to two (the most important come first). Times are
 * rounded to whole frames (30 per second).
 */
final class VideoTimeline
{
    /**
     * Frames per second of the video.
     */
    public const FPS = 30;

    /**
     * How long after the cut the voice starts, how long the scene stays after the voice, and the breath
     * between two sentences.
     */
    public const VOICE_LEAD = 0.35;

    public const VOICE_TAIL = 0.45;

    public const SENTENCE_GAP = 0.14;

    /**
     * How long before its words the camera starts moving to a shot.
     */
    public const SHOT_LEAD = 0.3;

    /**
     * Without a voice, a character of narration is given this many seconds on the screen.
     */
    public const READING_SECONDS_PER_CHARACTER = 0.06;

    /**
     * The fewest „Ce face” scenes kept when the video is too long.
     */
    public const MIN_FEATURES = 2;

    /**
     * The shortest scene of each kind: the time the camera needs to arrive and the viewer to look.
     *
     * @var array<string, float>
     */
    public const MIN_SECONDS = [
        'hook' => 3.0,
        'problem' => 3.0,
        'feature' => 3.0,
        'built' => 3.4,
        'proof' => 3.4,
        'start' => 3.8,
        'cta' => 3.8,
    ];

    /**
     * Place the scenes on the timeline.
     *
     * @param  list<array<string, mixed>>  $scenes  the script's scenes of one language, with their shots
     * @param  list<list<array{text: string, seconds: float}>|null>  $voices  the sentences of each scene's voice with the length of their clips (null = no voice)
     * @param  float  $maxSeconds  the longest video
     * @return array{scenes: list<array<string, mixed>>, total: float} the kept scenes with start, duration, voice_at, voice_seconds, sentences, cues and timed shots
     */
    public static function plan(array $scenes, array $voices, float $maxSeconds): array
    {
        $planned = self::place($scenes, $voices);

        while ($planned['total'] > $maxSeconds) {
            $features = array_keys(array_filter($scenes, fn (array $scene): bool => $scene['key'] === 'feature'));

            if (count($features) <= self::MIN_FEATURES) {
                break;
            }

            $last = $features[count($features) - 1];
            unset($scenes[$last], $voices[$last]);
            $scenes = self::renumberFeatures(array_values($scenes));
            $voices = array_values($voices);
            $planned = self::place($scenes, $voices);
        }

        return $planned;
    }

    /**
     * One pass: every scene after the previous one.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<list<array{text: string, seconds: float}>|null>  $voices
     * @return array{scenes: list<array<string, mixed>>, total: float}
     */
    private static function place(array $scenes, array $voices): array
    {
        $start = 0.0;
        $placed = [];

        foreach ($scenes as $position => $scene) {
            $voice = $voices[$position] ?? null;
            $hasVoice = $voice !== null && $voice !== [];
            $clips = $hasVoice ? $voice : self::readingClips($scene);

            $sentences = [];
            $cursor = $start + self::VOICE_LEAD;

            foreach ($clips as $clip) {
                $sentences[] = ['text' => $clip['text'], 'start' => round($cursor, 3), 'end' => round($cursor + $clip['seconds'], 3)];
                $cursor += $clip['seconds'] + self::SENTENCE_GAP;
            }

            $voiceEnd = $sentences === [] ? $start + self::VOICE_LEAD : $sentences[count($sentences) - 1]['end'];
            $duration = self::frames(max(self::MIN_SECONDS[$scene['key']] ?? 3.0, $voiceEnd - $start + self::VOICE_TAIL));
            $sceneStart = self::frames($start);

            $placed[] = [
                ...$scene,
                'start' => $sceneStart,
                'duration' => $duration,
                'voice_at' => $hasVoice ? self::frames($start + self::VOICE_LEAD) : null,
                'voice_seconds' => $hasVoice ? round($voiceEnd - $start - self::VOICE_LEAD, 3) : null,
                'sentences' => $sentences,
                'cues' => array_merge(...array_map(
                    fn (array $sentence): array => Captions::cues($sentence['text'], $sentence['start'], $sentence['end']),
                    $sentences,
                ), ...[[]]),
                'shots' => self::timeShots($scene['shots'] ?? [], $sentences, $sceneStart, $duration),
            ];

            $start += $duration;
        }

        return ['scenes' => $placed, 'total' => self::frames($start)];
    }

    /**
     * Without a voice: the sentences of the narration, each "said" for as long as reading it takes.
     *
     * @param  array<string, mixed>  $scene
     * @return list<array{text: string, seconds: float}>
     */
    private static function readingClips(array $scene): array
    {
        return array_map(
            fn (string $sentence): array => ['text' => $sentence, 'seconds' => round(max(1.2, self::READING_SECONDS_PER_CHARACTER * mb_strlen($sentence)), 3)],
            Captions::sentences((string) ($scene['narration'] ?? '')),
        );
    }

    /**
     * The moment of each shot, in seconds from the start of its scene. A shot tied to a sentence starts
     * with that sentence ("at" is then a share of the sentence); the others are spread over the whole
     * narration. The first shot is on the screen from the first frame, and two shots are never closer
     * than a second (the later one moves).
     *
     * @param  list<array<string, mixed>>  $shots
     * @param  list<array{text: string, start: float, end: float}>  $sentences
     * @return list<array<string, mixed>>
     */
    private static function timeShots(array $shots, array $sentences, float $sceneStart, float $duration): array
    {
        $voiceStart = $sentences === [] ? $sceneStart : $sentences[0]['start'];
        $voiceEnd = $sentences === [] ? $sceneStart + $duration : $sentences[count($sentences) - 1]['end'];
        $previous = null;
        $timed = [];

        foreach ($shots as $position => $shot) {
            $sentence = isset($shot['sentence']) ? ($sentences[$shot['sentence']] ?? null) : null;
            [$from, $to] = $sentence === null ? [$voiceStart, $voiceEnd] : [$sentence['start'], $sentence['end']];
            // A shot tied to a sentence the narration does not have comes in the middle of what is said.
            $share = isset($shot['sentence']) && $sentence === null ? 0.45 : (float) ($shot['at'] ?? 0);
            $time = $from + $share * ($to - $from) - self::SHOT_LEAD - $sceneStart;
            $time = $position === 0 ? 0.0 : max($time, ($previous ?? 0.0) + 1.0);

            if ($time > $duration - 0.8) {
                continue;
            }

            $previous = $time;
            $timed[] = [...array_diff_key($shot, ['at' => true, 'sentence' => true]), 'time' => round($time, 3)];
        }

        return $timed;
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
