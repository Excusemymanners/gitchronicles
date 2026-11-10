<?php

namespace Tests\Unit\Media;

use App\Media\RomanianText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RomanianTextTest extends TestCase
{
    public function test_cedilla_letters_become_comma_below_letters(): void
    {
        $this->assertSame('Știință și țară, Țara', RomanianText::commaBelow('Ştiinţă şi ţară, Ţara'));
    }

    public function test_clean_removes_emoji_and_control_characters_and_squeezes_spaces(): void
    {
        $this->assertSame('Rapid și simplu', RomanianText::clean("  Rapid 🚀  şi\n\tsimplu\u{200B} ✨ "));
        $this->assertSame('', RomanianText::clean(" 🚀 \n"));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function counts(): array
    {
        return [
            'one' => [1, '1 stea'],
            'two' => [2, '2 stele'],
            'nineteen' => [19, '19 stele'],
            'twenty takes de' => [20, '20 de stele'],
            'one hundred takes de' => [100, '100 de stele'],
            'one hundred and one does not' => [101, '101 stele'],
            'one hundred and twenty takes de' => [120, '120 de stele'],
            'thousands get a dot and no de for 17' => [5017, '5.017 stele'],
            'thousands with de' => [81962, '81.962 de stele'],
        ];
    }

    #[DataProvider('counts')]
    public function test_a_count_follows_the_romanian_grammar(int $number, string $expected): void
    {
        $this->assertSame($expected, RomanianText::count($number, 'stea', 'stele'));
    }

    public function test_a_count_for_the_voice_has_plain_digits(): void
    {
        $this->assertSame('81962 de stele', RomanianText::count(81962, 'stea', 'stele', formatted: false));
    }

    public function test_phrases_are_joined_with_commas_and_a_final_si(): void
    {
        $this->assertSame('', RomanianText::join([]));
        $this->assertSame('PHP', RomanianText::join(['PHP']));
        $this->assertSame('PHP și TypeScript', RomanianText::join(['PHP', 'TypeScript']));
        $this->assertSame('a, b și c', RomanianText::join(['a', 'b', 'c']));
    }
}
