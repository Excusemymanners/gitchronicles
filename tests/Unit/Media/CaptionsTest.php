<?php

namespace Tests\Unit\Media;

use App\Media\Captions;
use PHPUnit\Framework\TestCase;

/**
 * The captions: a narration cut into sentences, a sentence cut into short phrases whose words are timed
 * inside the sentence's exact start and end, and the WebVTT file made of those phrases.
 */
class CaptionsTest extends TestCase
{
    public function test_a_narration_is_cut_into_its_sentences(): void
    {
        $this->assertSame(
            ['It has 82 thousand stars.', 'The latest commit is from November 2026.'],
            Captions::sentences('It has 82 thousand stars. The latest commit is from November 2026.'),
        );
        $this->assertSame(['Built with Node.js and Apache-2.0 code.'], Captions::sentences(' Built with Node.js and Apache-2.0 code. '));
        $this->assertSame([], Captions::sentences(''));
    }

    public function test_a_sentence_becomes_short_phrases_that_fill_its_time_in_order(): void
    {
        $cues = Captions::cues('Documentation has to keep up with the code, every single day.', 2.0, 6.0);
        $this->assertSame('It has stars,', Captions::cues('It has stars, forks and more things.', 0.0, 3.0)[0]['text']);

        $this->assertSame('Documentation has to keep up with the code, every single day.', implode(' ', array_column($cues, 'text')));
        $this->assertSame(2.0, $cues[0]['start']);
        $this->assertEqualsWithDelta(6.0, $cues[count($cues) - 1]['end'], 0.002);

        foreach ($cues as $position => $cue) {
            $this->assertLessThanOrEqual(Captions::PHRASE_WORDS + 1, count($cue['words']));
            $this->assertSame($cue['start'], $cue['words'][0]['start']);
            $this->assertGreaterThan($cue['start'], $cue['end']);

            if ($position > 0) {
                $this->assertSame($cues[$position - 1]['end'], $cue['start']);
            }
        }

        // No word is left alone on the screen.
        $this->assertSame(['Documentation has to', 'keep up with the', 'code, every single day.'], array_column($cues, 'text'));
    }

    public function test_a_longer_word_gets_more_of_the_time(): void
    {
        [$cue] = Captions::cues('A documentation', 0.0, 3.0);

        $short = $cue['words'][0]['end'] - $cue['words'][0]['start'];
        $long = $cue['words'][1]['end'] - $cue['words'][1]['start'];

        $this->assertGreaterThan($short * 3, $long);
    }

    public function test_the_webvtt_file_has_one_cue_per_phrase(): void
    {
        $scenes = [
            ['cues' => Captions::cues('Documentația care se scrie singură.', 0.35, 3.35)],
            ['cues' => []],
            ['cues' => [['start' => 67.0, 'end' => 70.5, 'text' => 'a --> b', 'words' => []]]],
        ];

        $vtt = Captions::webVtt($scenes);

        $this->assertStringStartsWith("WEBVTT\n\n1\n00:00:00.350 --> ", $vtt);
        $this->assertStringContainsString("Documentația care se\n\n2\n", $vtt);
        $this->assertStringContainsString("00:01:07.000 --> 00:01:10.500\na → b\n", $vtt);
    }
}
