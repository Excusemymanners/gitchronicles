<?php

namespace Tests\Unit\Search;

use App\Search\SearchTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SearchTermsTest extends TestCase
{
    public function test_a_romanian_question_loses_its_stop_words_and_diacritics_and_gets_the_english_words_of_code(): void
    {
        $terms = SearchTerms::fromQuestion('Unde se face autentificarea?');

        $this->assertSame(['autentifica', 'authenticat', 'login'], $terms);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function questionsAndTerms(): array
    {
        return [
            'romanian plural' => ['modulele fișierele', ['modul', 'fisier', 'file']],
            'words that only point to a named folder or file' => ['modulul fișierul folderul', []],
            'verbs that describe the question' => ['Unde sunt definite rutele? Where is it implemented?', ['rutel', 'route']],
            'english plural' => ['users routes', ['user', 'route']],
            'short words stay whole' => ['git api', ['git', 'api']],
            'identifiers are split into words' => ['LoginController', ['login', 'control']],
            'snake case is split into words' => ['handle_request', ['handl', 'reque']],
            'english stop words' => ['Where is the password checked?', ['passw', 'check']],
            'one-letter words and repeated words' => ['a b user user', ['user']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('questionsAndTerms')]
    public function test_turns_words_into_prefixes_the_code_search_understands(string $question, array $expected): void
    {
        $this->assertSame($expected, SearchTerms::fromQuestion($question));
    }

    public function test_a_question_of_stop_words_has_no_terms(): void
    {
        $this->assertSame([], SearchTerms::fromQuestion('Ce este asta și cum se face?'));
    }

    public function test_keeps_at_most_sixteen_terms(): void
    {
        $question = implode(' ', array_map(fn (int $number): string => "ab{$number}", range(1, 30)));

        $this->assertCount(SearchTerms::MAX_TERMS, SearchTerms::fromQuestion($question));
    }

    public function test_splits_compound_identifiers_for_the_index_and_keeps_the_rest_of_the_text(): void
    {
        $this->assertSame(
            'class Login Controller extends Base; parse HTTP Response(); Git Hub',
            SearchTerms::splitIdentifiers('class LoginController extends Base; parseHTTPResponse(); GitHub'),
        );
    }
}
