<?php

namespace App\Media\Speech;

use App\Enums\SentenceRole;

/**
 * How each sentence is delivered, whatever the engine: a small change of speed and of pitch decided by the
 * sentence's role in the story. It is deterministic (the same script always sounds the same) and small on
 * purpose: never more than MAX_CHANGE (8 %) either way, because a voice that "acts" more than that starts
 * to sound like a parody. The drivers translate it: Edge takes a rate and a pitch per request, Kokoro and
 * Piper only a speed, so for them the pitch is ignored.
 *
 * The role comes from the place of the sentence in the script:
 * - Hook: the first sentence of the opening scene, a little slower, so the idea lands;
 * - Problem: the sentences of the problem scene, slightly lower and calm;
 * - Turn: the first sentence after the problem, brighter and a little faster — "here is how it is solved";
 * - Closing: the last sentence of the video, slower and warm;
 * - Detail: everything else, unchanged.
 */
final class SpeechDelivery
{
    /**
     * The largest change of speed or pitch, as a share (0.08 = 8 %).
     */
    public const MAX_CHANGE = 0.08;

    /**
     * The change of each role: speed (negative = slower) and pitch (positive = higher), as shares.
     *
     * @var array<string, array{rate: float, pitch: float}>
     */
    private const SHAPES = [
        'hook' => ['rate' => -0.05, 'pitch' => 0.02],
        'problem' => ['rate' => -0.02, 'pitch' => -0.03],
        'turn' => ['rate' => 0.03, 'pitch' => 0.06],
        'detail' => ['rate' => 0.0, 'pitch' => 0.0],
        'closing' => ['rate' => -0.06, 'pitch' => 0.0],
    ];

    /**
     * The role of a sentence.
     *
     * @param  string  $sceneKey  the kind of its scene (hook, problem, feature, …)
     * @param  string|null  $previousSceneKey  the kind of the scene before, null for the first
     * @param  int  $number  the position of the sentence in its scene, from 0
     * @param  bool  $isLast  whether it is the last sentence of the last scene
     */
    public static function roleOf(string $sceneKey, ?string $previousSceneKey, int $number, bool $isLast): SentenceRole
    {
        return match (true) {
            $isLast => SentenceRole::Closing,
            $sceneKey === 'hook' && $number === 0 => SentenceRole::Hook,
            $sceneKey === 'problem' => SentenceRole::Problem,
            $previousSceneKey === 'problem' && $number === 0 => SentenceRole::Turn,
            default => SentenceRole::Detail,
        };
    }

    /**
     * The change of speed and pitch of a role, kept within MAX_CHANGE.
     *
     * @return array{rate: float, pitch: float}
     */
    public static function shapeOf(SentenceRole $role): array
    {
        $shape = self::SHAPES[$role->value];

        return [
            'rate' => max(-self::MAX_CHANGE, min(self::MAX_CHANGE, $shape['rate'])),
            'pitch' => max(-self::MAX_CHANGE, min(self::MAX_CHANGE, $shape['pitch'])),
        ];
    }

    /**
     * The shape of every sentence of the scenes' narration.
     *
     * @param  list<list<string>>  $sentencesOfScenes  the sentences of each scene
     * @param  list<string>  $sceneKeys  the kind of each scene
     * @return list<list<array{rate: float, pitch: float}>> the same layout as the sentences
     */
    public static function shapesFor(array $sentencesOfScenes, array $sceneKeys): array
    {
        $lastScene = null;

        foreach ($sentencesOfScenes as $position => $sentences) {
            if ($sentences !== []) {
                $lastScene = $position;
            }
        }

        $shapes = [];

        foreach ($sentencesOfScenes as $position => $sentences) {
            $shapes[$position] = [];

            foreach (array_keys($sentences) as $number) {
                $isLast = $position === $lastScene && $number === count($sentences) - 1;
                $shapes[$position][$number] = self::shapeOf(self::roleOf($sceneKeys[$position], $sceneKeys[$position - 1] ?? null, $number, $isLast));
            }
        }

        return $shapes;
    }
}
