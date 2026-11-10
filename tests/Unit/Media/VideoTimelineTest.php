<?php

namespace Tests\Unit\Media;

use App\Media\VideoTimeline;
use PHPUnit\Framework\TestCase;

class VideoTimelineTest extends TestCase
{
    public function test_a_scene_lasts_as_long_as_its_voice_plus_the_margins(): void
    {
        $plan = VideoTimeline::plan([
            ['key' => 'hook', 'text' => 'Salut'],
            ['key' => 'cta', 'text' => 'Pa'],
        ], [5.0, 4.0]);

        $this->assertSame(0.0, $plan['scenes'][0]['start']);
        $this->assertSame(5.767, $plan['scenes'][0]['duration']);
        $this->assertSame(0.3, $plan['scenes'][0]['voice_at']);
        $this->assertSame(5.0, $plan['scenes'][0]['voice_seconds']);
        $this->assertSame(5.767, $plan['scenes'][1]['start']);
        $this->assertSame(6.067, $plan['scenes'][1]['voice_at']);
        $this->assertSame(10.533, $plan['total']);
    }

    public function test_a_short_voice_does_not_cut_the_animation_of_the_scene(): void
    {
        $plan = VideoTimeline::plan([['key' => 'start', 'text' => 'Pornești', 'command' => 'npm install']], [1.0]);

        $this->assertSame(VideoTimeline::MIN_SECONDS['start'], $plan['scenes'][0]['duration']);
    }

    public function test_without_a_voice_a_scene_lasts_as_long_as_reading_it(): void
    {
        $plan = VideoTimeline::plan([
            ['key' => 'hook', 'title' => 'magazin', 'text' => 'Un magazin mic.'],
            ['key' => 'problem', 'text' => str_repeat('cuvinte ', 30)],
        ], [null, null]);

        $this->assertSame(VideoTimeline::MIN_SECONDS['hook'], $plan['scenes'][0]['duration']);
        $this->assertNull($plan['scenes'][0]['voice_at']);
        $this->assertNull($plan['scenes'][0]['voice_seconds']);
        $this->assertSame(6.5, $plan['scenes'][1]['duration']);
    }

    public function test_every_time_falls_on_a_whole_frame(): void
    {
        $plan = VideoTimeline::plan([['key' => 'hook', 'text' => 'a'], ['key' => 'cta', 'text' => 'b']], [3.3333, 3.7777]);

        foreach ($plan['scenes'] as $scene) {
            foreach (['start', 'duration', 'voice_at'] as $field) {
                $this->assertEqualsWithDelta(round($scene[$field] * VideoTimeline::FPS), $scene[$field] * VideoTimeline::FPS, 0.02, $field);
            }
        }
    }

    public function test_a_video_that_would_be_too_long_loses_its_third_feature(): void
    {
        $scenes = [
            ['key' => 'hook', 'text' => 'Ce este'],
            ['key' => 'feature', 'index' => 1, 'count' => 3, 'title' => 'Unu'],
            ['key' => 'feature', 'index' => 2, 'count' => 3, 'title' => 'Doi'],
            ['key' => 'feature', 'index' => 3, 'count' => 3, 'title' => 'Trei'],
            ['key' => 'cta', 'text' => 'Final'],
        ];

        $plan = VideoTimeline::plan($scenes, [8.0, 9.0, 9.0, 9.0, 4.0]);

        $this->assertSame(['hook', 'feature', 'feature', 'cta'], array_column($plan['scenes'], 'key'));
        $this->assertSame(['Unu', 'Doi'], array_values(array_filter(array_column($plan['scenes'], 'title'))));
        $this->assertSame([1, 2], [$plan['scenes'][1]['index'], $plan['scenes'][2]['index']]);
        $this->assertSame([2, 2], [$plan['scenes'][1]['count'], $plan['scenes'][2]['count']]);
        $this->assertSame(4.0, $plan['scenes'][3]['voice_seconds']);
        $this->assertLessThan(VideoTimeline::MAX_TOTAL_SECONDS, $plan['total']);
    }

    public function test_a_video_short_enough_keeps_its_three_features(): void
    {
        $scenes = [
            ['key' => 'hook', 'text' => 'Ce este'],
            ['key' => 'feature', 'index' => 1, 'count' => 3, 'title' => 'Unu'],
            ['key' => 'feature', 'index' => 2, 'count' => 3, 'title' => 'Doi'],
            ['key' => 'feature', 'index' => 3, 'count' => 3, 'title' => 'Trei'],
        ];

        $plan = VideoTimeline::plan($scenes, [3.0, 4.0, 4.0, 4.0]);

        $this->assertCount(4, $plan['scenes']);
    }
}
