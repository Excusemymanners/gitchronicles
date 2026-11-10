<?php

namespace Tests\Unit\Media;

use App\Media\Captions;
use PHPUnit\Framework\TestCase;

class CaptionsTest extends TestCase
{
    public function test_every_scene_with_words_becomes_a_cue_timed_to_its_voice(): void
    {
        $vtt = Captions::webVtt([
            ['key' => 'hook', 'narration' => 'Documentația care se scrie singură.', 'start' => 0.0, 'duration' => 4.0, 'voice_at' => 0.3, 'voice_seconds' => 3.25],
            ['key' => 'proof', 'narration' => '', 'start' => 4.0, 'duration' => 3.0, 'voice_at' => null, 'voice_seconds' => null],
            ['key' => 'cta', 'narration' => 'Îl găsești pe GitHub.', 'start' => 67.0, 'duration' => 3.5, 'voice_at' => null, 'voice_seconds' => null],
        ]);

        $this->assertSame(implode("\n", [
            'WEBVTT',
            '',
            '1',
            '00:00:00.300 --> 00:00:03.550',
            'Documentația care se scrie singură.',
            '',
            '3',
            '00:01:07.000 --> 00:01:10.500',
            'Îl găsești pe GitHub.',
            '',
        ]), $vtt);
    }
}
