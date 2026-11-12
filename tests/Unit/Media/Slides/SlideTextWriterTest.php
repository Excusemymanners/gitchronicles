<?php

namespace Tests\Unit\Media\Slides;

use App\Ai\AiResult;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Media\Slides\Slide;
use App\Media\Slides\SlideTextWriter;
use Tests\TestCase;

class SlideTextWriterTest extends TestCase
{
    private const PAGE = 'The module `app/Http` holds the controllers and the middleware. Controllers answer 3 kinds of requests with Inertia.';

    public function test_the_json_object_is_found_inside_text_or_a_code_block(): void
    {
        $this->assertSame(['slides' => ['a' => ['headline' => 'x']]], SlideTextWriter::decode("```json\n{\"slides\": {\"a\": {\"headline\": \"x\"}}}\n```"));
        $this->assertNull(SlideTextWriter::decode('no json here'));
        $this->assertNull(SlideTextWriter::decode('{"slides": 5}'));
        $this->assertNull(SlideTextWriter::decode('{"slides": {"a": '));
    }

    public function test_a_text_is_grounded_only_when_its_numbers_and_names_are_in_the_page(): void
    {
        $this->assertTrue(SlideTextWriter::isGrounded('The controllers answer 3 kinds of requests', self::PAGE));
        $this->assertFalse(SlideTextWriter::isGrounded('The controllers answer 5 kinds of requests', self::PAGE));
        $this->assertFalse(SlideTextWriter::isGrounded('It uses `app/Models` for data', self::PAGE));
        $this->assertFalse(SlideTextWriter::isGrounded('It is built with Laravel and Inertia', self::PAGE));
        $this->assertTrue(SlideTextWriter::isGrounded('Built on Inertia', self::PAGE));
    }

    public function test_a_valid_answer_replaces_the_headline_and_the_bullets(): void
    {
        $slide = $this->moduleSlide();

        $improved = SlideTextWriter::apply($slide, ['headline' => 'The HTTP layer of the app', 'bullets' => ['Controllers answer 3 kinds of requests', 'Middleware shares data']], self::PAGE);

        $this->assertSame('The HTTP layer of the app', $improved->headline);
        $this->assertSame(['Controllers answer 3 kinds of requests', 'Middleware shares data'], $improved->bullets);
    }

    public function test_a_malformed_too_long_or_invented_answer_keeps_the_slide_as_it_is(): void
    {
        $slide = $this->moduleSlide();
        $tooMany = ['a one', 'b two', 'c three', 'd four', 'e five'];

        $this->assertNull(SlideTextWriter::apply($slide, 'text', self::PAGE));
        $this->assertNull(SlideTextWriter::apply($slide, ['bullets' => ['x', 'y']], self::PAGE));
        $this->assertNull(SlideTextWriter::apply($slide, ['headline' => 'Fine', 'bullets' => ['only one']], self::PAGE));
        $this->assertNull(SlideTextWriter::apply($slide, ['headline' => 'Fine', 'bullets' => $tooMany], self::PAGE));
        $this->assertNull(SlideTextWriter::apply($slide, ['headline' => str_repeat('long ', 30), 'bullets' => ['a b', 'c d']], self::PAGE));
        $this->assertNull(SlideTextWriter::apply($slide, ['headline' => 'Fine', 'bullets' => [str_repeat('word ', 20), 'c d']], self::PAGE));
        $this->assertNull(SlideTextWriter::apply($slide, ['headline' => 'Handles 99 requests', 'bullets' => ['a b', 'c d']], self::PAGE));
        $this->assertNull(SlideTextWriter::apply($slide, ['headline' => 'Fine', 'bullets' => [1, 2]], self::PAGE));
    }

    public function test_improve_makes_one_call_and_changes_only_valid_slides(): void
    {
        $generator = new class implements TextGenerator
        {
            public int $calls = 0;

            public function generate(string $system, string $user): AiResult
            {
                $this->calls++;

                return new AiResult('{"slides": {"module:app/Http": {"headline": "The HTTP layer", "bullets": ["Controllers answer 3 kinds of requests", "Middleware shares data"]}, "what": {"headline": "Invented 42 things"}}}', 1, 1, null, 'test', 'test');
            }
        };
        $slides = [
            new Slide('what', 'statement', 'Original', source: 'overview'),
            $this->moduleSlide(),
            new Slide('facts', 'facts', 'Numbers'),
        ];
        $source = ['overview' => "## What the project does\nIt does things.", 'modules' => [['path' => 'app/Http', 'lines' => 5, 'markdown' => '# Module `app/Http`'."\n\n## Role\n".self::PAGE]]];

        $result = (new SlideTextWriter($generator))->improve($slides, $source, ['owner' => 'a', 'name' => 'b']);

        $this->assertSame(1, $generator->calls);
        $this->assertSame('Original', $result[0]->headline);
        $this->assertSame('The HTTP layer', $result[1]->headline);
        $this->assertSame('Numbers', $result[2]->headline);
    }

    public function test_without_ai_nothing_changes(): void
    {
        $slides = [$this->moduleSlide()];
        $source = ['modules' => [['path' => 'app/Http', 'lines' => 5, 'markdown' => self::PAGE]]];

        $this->assertSame($slides, (new SlideTextWriter(new NullTextGenerator))->improve($slides, $source, ['owner' => 'a', 'name' => 'b']));
    }

    private function moduleSlide(): Slide
    {
        return new Slide('module:app/Http', 'bullets', 'Original', bullets: ['One', 'Two', 'Three']);
    }
}
