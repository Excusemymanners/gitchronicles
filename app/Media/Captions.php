<?php

namespace App\Media;

/**
 * The captions of the video, in the look of the reels: a few words at a time, with the word being said
 * highlighted.
 *
 * The voice is recorded one sentence per clip (Narrator), so the start and the end of every sentence are
 * exact. Inside a sentence the moment of each word is estimated from its length: Piper does not say when
 * it speaks each word, and a sentence is short enough for the estimate to stay within a fraction of a
 * second.
 *
 * - sentences(): a narration cut into sentences;
 * - cues(): one sentence cut into short phrases ("cues"), each with its words and their times;
 * - webVtt(): the cues of the whole video as a WebVTT file for the <track> element, which makes the
 *   video readable without sound and gives screen readers its text.
 */
final class Captions
{
    /**
     * A phrase on the screen has at most this many characters and words (two short lines).
     */
    public const PHRASE_CHARACTERS = 24;

    public const PHRASE_WORDS = 4;

    /**
     * A narration as its sentences.
     *
     * @return list<string>
     */
    public static function sentences(string $narration): array
    {
        $sentences = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\p{N}„"“])/u', trim($narration)) ?: [];

        return array_values(array_filter(array_map('trim', $sentences), fn (string $sentence): bool => $sentence !== ''));
    }

    /**
     * One sentence, said between $start and $end (seconds), as phrases with timed words.
     *
     * A word's share of the time is its number of letters, plus a little for the pause after a comma or
     * a full stop. A phrase ends at punctuation, or when it is long enough; a last word is never left alone.
     *
     * @return list<array{start: float, end: float, text: string, words: list<array{text: string, start: float, end: float}>}>
     */
    public static function cues(string $sentence, float $start, float $end): array
    {
        $words = preg_split('/\s+/u', trim($sentence), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === [] || $end <= $start) {
            return [];
        }

        $weights = array_map(
            fn (string $word): float => max(1, mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $word) ?? $word)) + 1.2
                + (preg_match('/[,;:.!?…]$/u', $word) === 1 ? 2.5 : 0),
            $words,
        );
        $perUnit = ($end - $start) / array_sum($weights);

        $timed = [];
        $cursor = $start;

        foreach ($words as $position => $word) {
            $until = $cursor + $weights[$position] * $perUnit;
            $timed[] = ['text' => $word, 'start' => round($cursor, 3), 'end' => round($until, 3)];
            $cursor = $until;
        }

        $phrases = [];
        $phrase = [];

        foreach ($timed as $position => $word) {
            $length = mb_strlen(implode(' ', array_column($phrase, 'text')));
            $isFull = $phrase !== [] && (count($phrase) >= self::PHRASE_WORDS || $length + 1 + mb_strlen($word['text']) > self::PHRASE_CHARACTERS);

            if ($isFull) {
                $phrases[] = $phrase;
                $phrase = [];
            }

            $phrase[] = $word;

            // Punctuation closes the phrase, when it is not a single short word and something follows.
            if (preg_match('/[,;:.!?…]$/u', $word['text']) === 1 && count($phrase) >= 2 && $position < count($timed) - 1) {
                $phrases[] = $phrase;
                $phrase = [];
            }
        }

        if ($phrase !== []) {
            $last = count($phrases) - 1;

            if (count($phrase) === 1 && $last >= 0 && mb_strlen(implode(' ', array_column([...$phrases[$last], ...$phrase], 'text'))) <= self::PHRASE_CHARACTERS + 8) {
                $phrases[$last] = [...$phrases[$last], ...$phrase];
            } else {
                $phrases[] = $phrase;
            }
        }

        return array_map(fn (array $phrase): array => [
            'start' => $phrase[0]['start'],
            'end' => $phrase[count($phrase) - 1]['end'],
            'text' => implode(' ', array_column($phrase, 'text')),
            'words' => $phrase,
        ], $phrases);
    }

    /**
     * The WebVTT file of the planned scenes (VideoTimeline::plan()): one cue per phrase.
     *
     * @param  list<array<string, mixed>>  $scenes
     */
    public static function webVtt(array $scenes): string
    {
        $lines = ['WEBVTT', ''];
        $number = 0;

        foreach ($scenes as $scene) {
            foreach ($scene['cues'] ?? [] as $cue) {
                $lines[] = (string) ++$number;
                $lines[] = self::time((float) $cue['start']).' --> '.self::time((float) $cue['end']);
                // "-->" would end the cue's timing line; it never appears in a narration, but stays harmless.
                $lines[] = str_replace('-->', '→', $cue['text']);
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A time in the WebVTT format: 00:00:03.250.
     */
    private static function time(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);

        return sprintf('%02d:%02d:%02d.%03d', intdiv($milliseconds, 3_600_000), intdiv($milliseconds, 60_000) % 60, intdiv($milliseconds, 1000) % 60, $milliseconds % 1000);
    }
}
