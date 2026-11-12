<?php

namespace Tests\Unit\Media;

use App\Enums\SentenceRole;
use App\Media\Speech\SpeechDelivery;
use PHPUnit\Framework\TestCase;

/**
 * The role of each sentence and the small change of speed and pitch it gets.
 */
class SpeechDeliveryTest extends TestCase
{
    public function test_roles_come_from_the_place_of_the_sentence_in_the_script(): void
    {
        $scenes = [['One.', 'Two.'], ['Problem.', 'More.'], ['Solution.', 'Detail.'], ['Bye.']];
        $keys = ['hook', 'problem', 'feature', 'cta'];

        $roles = SpeechDelivery::shapesFor($scenes, $keys);

        $this->assertSame(SpeechDelivery::shapeOf(SentenceRole::Hook), $roles[0][0]);
        $this->assertSame(SpeechDelivery::shapeOf(SentenceRole::Detail), $roles[0][1]);
        $this->assertSame(SpeechDelivery::shapeOf(SentenceRole::Problem), $roles[1][1]);
        $this->assertSame(SpeechDelivery::shapeOf(SentenceRole::Turn), $roles[2][0]);
        $this->assertSame(SpeechDelivery::shapeOf(SentenceRole::Detail), $roles[2][1]);
        $this->assertSame(SpeechDelivery::shapeOf(SentenceRole::Closing), $roles[3][0]);
        $this->assertSame(SentenceRole::Turn, SpeechDelivery::roleOf('feature', 'problem', 0, false));
        $this->assertSame(SentenceRole::Closing, SpeechDelivery::roleOf('cta', 'feature', 0, true));
    }

    public function test_the_hook_and_the_closing_are_slower_the_turn_is_brighter_and_nothing_exceeds_eight_percent(): void
    {
        $this->assertLessThan(0, SpeechDelivery::shapeOf(SentenceRole::Hook)['rate']);
        $this->assertLessThan(0, SpeechDelivery::shapeOf(SentenceRole::Closing)['rate']);
        $this->assertGreaterThan(0, SpeechDelivery::shapeOf(SentenceRole::Turn)['pitch']);
        $this->assertSame(['rate' => 0.0, 'pitch' => 0.0], SpeechDelivery::shapeOf(SentenceRole::Detail));

        foreach (SentenceRole::cases() as $role) {
            $shape = SpeechDelivery::shapeOf($role);
            $this->assertLessThanOrEqual(SpeechDelivery::MAX_CHANGE, abs($shape['rate']));
            $this->assertLessThanOrEqual(SpeechDelivery::MAX_CHANGE, abs($shape['pitch']));
        }
    }

    public function test_the_same_script_always_gets_the_same_shapes(): void
    {
        $scenes = [['A.'], ['B.']];

        $this->assertSame(SpeechDelivery::shapesFor($scenes, ['hook', 'cta']), SpeechDelivery::shapesFor($scenes, ['hook', 'cta']));
    }
}
