<?php

namespace App\Media;

/**
 * The captions of the video (WebVTT, the format of the <track> element): one cue per scene, with the words
 * the voice says, shown while it says them. Without a voice, a cue lasts as long as its scene.
 * They make the video readable without sound and give screen readers its text.
 */
final class Captions
{
    /**
     * The WebVTT file of the planned scenes (VideoTimeline::plan()).
     *
     * @param  list<array<string, mixed>>  $scenes
     */
    public static function webVtt(array $scenes): string
    {
        $cues = ['WEBVTT', ''];

        foreach ($scenes as $position => $scene) {
            $text = trim((string) ($scene['narration'] ?? ''));

            if ($text === '') {
                continue;
            }

            $start = $scene['voice_at'] ?? $scene['start'];
            $end = $scene['voice_at'] === null ? $scene['start'] + $scene['duration'] : $scene['voice_at'] + $scene['voice_seconds'];

            $cues[] = (string) ($position + 1);
            $cues[] = self::time((float) $start).' --> '.self::time((float) $end);
            // "-->" would end the cue's timing line; it never appears in a narration, but stays harmless.
            $cues[] = str_replace('-->', '→', $text);
            $cues[] = '';
        }

        return implode("\n", $cues);
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
