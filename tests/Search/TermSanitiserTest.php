<?php

namespace Proteo\DrupalBackend\Tests\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Proteo\DrupalBackend\Descriptor\SearchConfig;
use Proteo\DrupalBackend\Search\TermSanitiser;

class TermSanitiserTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function usableTerms(): array
    {
        return [
            'plain words' => ['Frantz Fanon', 'frantz fanon', ['frantz', 'fanon']],
            'whitespace collapsed' => ["  frantz \t\n  fanon  ", 'frantz fanon', ['frantz', 'fanon']],
            'reserved characters become spaces' => ['a+b-c!d(e)f{g}h[i]j', 'a b c d e f g h i j', ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j']],
            'remaining reserved characters become spaces' => ['k^l~m*n?o:p\\q/r&s|t', 'k l m n o p q r s t', ['k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't']],
            'uppercase operators are neutralised' => ['cats AND dogs OR birds NOT fish', 'cats and dogs or birds not fish', ['cats', 'and', 'dogs', 'or', 'birds', 'not', 'fish']],
            'hyphen splits words' => ['post-colonial', 'post colonial', ['post', 'colonial']],
            'leading minus does not exclude' => ['-fanon', 'fanon', ['fanon']],
            'balanced phrase is kept' => ['the "Wretched of the Earth"', 'the "wretched of the earth"', ['the', 'wretched of the earth']],
            'phrase with reserved characters' => ['"black-skin, white masks"', '"black skin, white masks"', ['black skin, white masks']],
            'two phrases' => ['"a b" "c d"', '"a b" "c d"', ['a b', 'c d']],
            'unbalanced opening quote is dropped' => ['"frantz fanon', 'frantz fanon', ['frantz', 'fanon']],
            'unbalanced quote after a phrase is dropped' => ['"a b" "c d', '"a b" c d', ['a b', 'c', 'd']],
            'empty phrase is dropped' => ['fanon ""', 'fanon', ['fanon']],
            'accents and multi-byte are kept' => ['Édouard Glissant', 'édouard glissant', ['édouard', 'glissant']],
            'excess words are dropped' => ['one two three four five six seven eight nine ten eleven twelve', 'one two three four five six seven eight nine ten', ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten']],
            'phrase words count toward the word limit' => ['a1 b1 c1 d1 e1 f1 g1 h1 "i1 j1 k1 l1"', 'a1 b1 c1 d1 e1 f1 g1 h1 "i1 j1"', ['a1', 'b1', 'c1', 'd1', 'e1', 'f1', 'g1', 'h1', 'i1 j1']],
            'minimum length' => ['ab', 'ab', ['ab']],
        ];
    }

    #[DataProvider('usableTerms')]
    public function test_it_sanitises_usable_terms(string $input, string $fulltext, array $terms): void
    {
        $term = (new TermSanitiser)->sanitise($input);

        $this->assertTrue($term->isUsable());
        $this->assertSame($fulltext, $term->fulltext);
        $this->assertSame($terms, $term->terms);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableTerms(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \t\n "],
            'only reserved characters' => ['+-!(){}[]^~*?:\\/&|'],
            'only quotes' => ['""'],
            'single character' => ['a'],
            'single character among reserved characters' => ['  -a* '],
        ];
    }

    #[DataProvider('unusableTerms')]
    public function test_it_reports_no_usable_term(string $input): void
    {
        $term = (new TermSanitiser)->sanitise($input);

        $this->assertFalse($term->isUsable());
        $this->assertSame([], $term->terms);
        $this->assertSame('', $term->fulltext);
    }

    public function test_it_truncates_to_one_hundred_characters(): void
    {
        $term = (new TermSanitiser)->sanitise(str_repeat('é', 150));

        $this->assertSame(str_repeat('é', 100), $term->fulltext);
    }

    public function test_truncation_that_cuts_a_phrase_leaves_no_unbalanced_quote(): void
    {
        $term = (new TermSanitiser)->sanitise('"'.str_repeat('a', 150).'"');

        $this->assertStringNotContainsString('"', $term->fulltext);
        $this->assertSame([str_repeat('a', 99)], $term->terms);
    }

    public function test_limits_come_from_the_search_config(): void
    {
        $sanitiser = new TermSanitiser(new SearchConfig(termMin: 4, termMax: 12, termMaxWords: 2));

        $this->assertSame(4, $sanitiser->minLength());
        $this->assertSame(12, $sanitiser->maxLength());
        $this->assertFalse($sanitiser->sanitise('abc')->isUsable());
        $this->assertSame(['one', 'two'], $sanitiser->sanitise('one two three')->terms);
        $this->assertSame('abcdefghijkl', $sanitiser->sanitise(str_repeat('abcdefghijkl', 2))->fulltext);
    }
}
