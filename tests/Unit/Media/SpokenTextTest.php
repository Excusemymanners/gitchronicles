<?php

namespace Tests\Unit\Media;

use App\Enums\MediaLanguage;
use App\Media\EnglishText;
use App\Media\RomanianText;
use App\Media\SpokenText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the voices are given: counts worded in each language, technology names respelled only for the
 * voice that would misread them, and the guess of a text's language.
 */
class SpokenTextTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function counts(): array
    {
        return [
            'one' => [1, '1 star', '1 stea'],
            'few' => [14, '14 stars', '14 stele'],
            'twenty and more' => [120, '120 stars', '120 de stele'],
            'one thousand and something' => [1500, 'over 1 thousand stars', 'peste o mie de stele'],
            'thousands' => [7572, 'over 7 thousand stars', 'peste 7 mii de stele'],
            'tens of thousands' => [81962, 'over 81 thousand stars', 'peste 81 de mii de stele'],
            'a round number' => [3000, '3 thousand stars', '3 mii de stele'],
            'millions' => [2_400_000, 'over 2 million stars', 'peste 2 milioane de stele'],
        ];
    }

    #[DataProvider('counts')]
    public function test_a_count_is_worded_for_each_voice(int $number, string $english, string $romanian): void
    {
        $this->assertSame($english, SpokenText::count($number, 'star', MediaLanguage::En));
        $this->assertSame($romanian, SpokenText::count($number, 'star', MediaLanguage::Ro));
    }

    public function test_english_counts_numbers_and_lists(): void
    {
        $this->assertSame('5,017 lines', EnglishText::count(5017, 'line', 'lines'));
        $this->assertSame('1 line', EnglishText::count(1, 'line', 'lines'));
        $this->assertSame('PHP, TypeScript and CSS', EnglishText::join(['PHP', 'TypeScript', 'CSS']));
        $this->assertSame('PHP și CSS', RomanianText::join(['PHP', 'CSS']));
        $this->assertSame('November 2026', SpokenText::monthYear('2026-11-10T09:54:42+00:00', MediaLanguage::En));
        $this->assertSame('noiembrie 2026', SpokenText::monthYear('2026-11-10T09:54:42+00:00', MediaLanguage::Ro));
    }

    public function test_technology_names_are_respelled_only_for_the_voice_that_needs_it(): void
    {
        $this->assertSame('E scris în taipscript și paiton, cu ghithab.', SpokenText::for('E scris în TypeScript și Python, cu GitHub.', MediaLanguage::Ro));
        $this->assertSame('Written in TypeScript and Python. Run N P M install from the read me.', SpokenText::for('Written in TypeScript and Python. Run `npm` install from the README.', MediaLanguage::En));
        // "ai" is an ordinary Romanian word; only the abbreviation is spelled out.
        $this->assertSame('Tu ai un asistent ei ai.', SpokenText::for('Tu ai un asistent AI.', MediaLanguage::Ro));
        $this->assertSame('modulul app Media', SpokenText::for('modulul app/Media', MediaLanguage::Ro));
    }

    public function test_the_language_of_a_text_is_guessed_or_left_unknown(): void
    {
        $this->assertSame(MediaLanguage::Ro, SpokenText::languageOf('Asistent AI care documentează automat un repository.'));
        $this->assertSame(MediaLanguage::En, SpokenText::languageOf('Write HTML. Render video. Built for agents.'));
        $this->assertNull(SpokenText::languageOf('hello-world'));
    }

    public function test_sentence_final_punctuation_and_dashes_survive_the_respelling(): void
    {
        $spoken = SpokenText::for('Why does `npm` fail? Because of the README — and that is fine… Really!', MediaLanguage::En);
        $this->assertSame('Why does N P M fail? Because of the read me — and that is fine… Really!', $spoken);
        $this->assertSame('De ce eșuează ghit? Pentru că – ei bine – așa e!', SpokenText::for('De ce eșuează Git? Pentru că – ei bine – așa e!', MediaLanguage::Ro));
    }
}
