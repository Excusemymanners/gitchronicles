<?php

namespace Tests\Unit\Media;

use App\Media\VideoTimeline;
use PHPUnit\Framework\TestCase;

/**
 * The timeline: scenes as long as their voice, sentences one after another, the camera shots timed to
 * the sentences they illustrate, and the last features left out when the video would be too long.
 */
class VideoTimelineTest extends TestCase
{
    public function test_a_scene_lasts_as_long_as_its_sentences_plus_the_margins(): void
    {
        $plan = VideoTimeline::plan(
            [['key' => 'hook', 'narration' => 'One. Two.'], ['key' => 'cta', 'narration' => 'Bye.']],
            [[['text' => 'One.', 'seconds' => 2.0], ['text' => 'Two.', 'seconds' => 3.0]], [['text' => 'Bye.', 'seconds' => 1.0]]],
            60,
        );

        [$hook, $cta] = $plan['scenes'];
        // 0.35 lead + 2 + 0.14 breath + 3 + 0.45 tail = 5.94 → 5.933 on a whole frame.
        $this->assertSame([0.0, 5.933, 0.367], [$hook['start'], $hook['duration'], $hook['voice_at']]);
        $this->assertSame([['text' => 'One.', 'start' => 0.35, 'end' => 2.35], ['text' => 'Two.', 'start' => 2.49, 'end' => 5.49]], $hook['sentences']);
        $this->assertSame('One.', $hook['cues'][0]['text']);
        // A short voice does not cut the scene under its minimum.
        $this->assertSame([5.933, VideoTimeline::MIN_SECONDS['cta']], [$cta['start'], $cta['duration']]);
        $this->assertSame(9.733, $plan['total']);
    }

    public function test_without_a_voice_a_scene_lasts_as_long_as_reading_it_and_still_has_captions(): void
    {
        $plan = VideoTimeline::plan([['key' => 'problem', 'narration' => str_repeat('cuvinte ', 12).'gata.']], [null], 60);

        [$scene] = $plan['scenes'];
        $this->assertNull($scene['voice_at']);
        $this->assertEqualsWithDelta(0.35 + 101 * VideoTimeline::READING_SECONDS_PER_CHARACTER + 0.45, $scene['duration'], 0.04);
        $this->assertNotEmpty($scene['cues']);
    }

    public function test_a_shot_starts_just_before_the_sentence_it_illustrates(): void
    {
        $shots = [
            ['kind' => 'stars', 'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'at' => 0.0, 'sentence' => 0],
            ['kind' => 'forks', 'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'at' => 0.5, 'sentence' => 0],
            ['kind' => 'commit', 'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'at' => 0.0, 'sentence' => 1],
        ];

        $plan = VideoTimeline::plan(
            [['key' => 'proof', 'narration' => 'Stars and forks. Commits.', 'shots' => $shots]],
            [[['text' => 'Stars and forks.', 'seconds' => 4.0], ['text' => 'Commits.', 'seconds' => 3.0]]],
            60,
        );

        $timed = $plan['scenes'][0]['shots'];
        $this->assertSame(['stars', 'forks', 'commit'], array_column($timed, 'kind'));
        // The first is on screen from the first frame; the second at the middle of sentence 1; the third with sentence 2.
        $this->assertEqualsWithDelta(0.0, $timed[0]['time'], 0.001);
        $this->assertEqualsWithDelta(0.35 + 2.0 - VideoTimeline::SHOT_LEAD, $timed[1]['time'], 0.001);
        $this->assertEqualsWithDelta(0.35 + 4.0 + 0.14 - VideoTimeline::SHOT_LEAD, $timed[2]['time'], 0.001);
        $this->assertArrayNotHasKey('sentence', $timed[0]);
    }

    public function test_a_video_that_would_be_too_long_loses_its_last_features_down_to_two(): void
    {
        $scenes = [['key' => 'hook', 'narration' => 'A.']];
        $voices = [[['text' => 'A.', 'seconds' => 4.0]]];

        foreach ([1, 2, 3, 4] as $index) {
            $scenes[] = ['key' => 'feature', 'index' => $index, 'count' => 4, 'narration' => "F{$index}."];
            $voices[] = [['text' => "F{$index}.", 'seconds' => 9.0]];
        }

        $scenes[] = ['key' => 'cta', 'narration' => 'Z.'];
        $voices[] = [['text' => 'Z.', 'seconds' => 4.0]];

        $long = VideoTimeline::plan($scenes, $voices, 40);
        $this->assertSame(['hook', 'feature', 'feature', 'feature', 'cta'], array_column($long['scenes'], 'key'));
        $this->assertSame([[1, 3], [2, 3], [3, 3]], array_map(fn (array $scene): array => [$scene['index'], $scene['count']], array_slice($long['scenes'], 1, 3)));
        $this->assertSame('Z.', $long['scenes'][4]['sentences'][0]['text']);

        $tooLong = VideoTimeline::plan($scenes, $voices, 20);
        $this->assertSame(['hook', 'feature', 'feature', 'cta'], array_column($tooLong['scenes'], 'key'));

        $fits = VideoTimeline::plan($scenes, $voices, 60);
        $this->assertCount(6, $fits['scenes']);
    }

    public function test_every_time_falls_on_a_whole_frame(): void
    {
        $plan = VideoTimeline::plan(
            [['key' => 'hook', 'narration' => 'A.'], ['key' => 'feature', 'narration' => 'B.']],
            [[['text' => 'A.', 'seconds' => 2.777]], [['text' => 'B.', 'seconds' => 3.123]]],
            60,
        );

        foreach ($plan['scenes'] as $scene) {
            foreach ([$scene['start'], $scene['duration']] as $seconds) {
                $this->assertEqualsWithDelta(round($seconds * 30), $seconds * 30, 0.02);
            }
        }
    }
}
