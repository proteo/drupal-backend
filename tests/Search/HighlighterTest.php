<?php

namespace Proteo\DrupalBackend\Tests\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Proteo\DrupalBackend\Search\Highlighter;

class HighlighterTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function cases(): array
    {
        return [
            'substring match' => ['Washington', ['wash'], '<mark>Wash</mark>ington'],
            'case-insensitive keeps original case' => ['The EARTH', ['earth'], 'The <mark>EARTH</mark>'],
            'repeated matches' => ['aa bb aa', ['aa'], '<mark>aa</mark> bb <mark>aa</mark>'],
            'several terms' => ['Frantz Fanon', ['fanon', 'frantz'], '<mark>Frantz</mark> <mark>Fanon</mark>'],
            'overlapping terms are merged' => ['abcdef', ['abcd', 'cdef'], '<mark>abcdef</mark>'],
            'contained term is absorbed by the longer match' => ['abcdef', ['bc', 'abcdef'], '<mark>abcdef</mark>'],
            'phrase is one unit' => ['The Wretched of the Earth', ['wretched of the'], 'The <mark>Wretched of the</mark> Earth'],
            'phrase does not match across different spacing' => ['wretched  of', ['wretched of'], 'wretched  of'],
            'accents are significant' => ['Édouard Edouard', ['édouard'], '<mark>Édouard</mark> Edouard'],
            'multi-byte text' => ['Ñandú y ñandú', ['ñandú'], '<mark>Ñandú</mark> y <mark>ñandú</mark>'],
            'terms under two characters are ignored' => ['a banana', ['a'], 'a banana'],
            'empty term list returns escaped text' => ['A <b>bold</b> & "quoted"', [], 'A &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;'],
            'no match returns escaped text' => ['plain', ['zzz'], 'plain'],
            'regex metacharacters in terms are literal' => ['a.b (c)', ['a.b', '(c)'], '<mark>a.b</mark> <mark>(c)</mark>'],
            'script payload in text stays escaped' => ['<script>alert(1)</script>', ['script'], '&lt;<mark>script</mark>&gt;alert(1)&lt;/<mark>script</mark>&gt;'],
            'quote and ampersand payloads stay escaped' => ['Tom & "Jerry" \'s', ['jerry'], 'Tom &amp; &quot;<mark>Jerry</mark>&quot; &#039;s'],
            'term that is itself markup is matched as text' => ['x <b> y', ['<b>'], 'x <mark>&lt;b&gt;</mark> y'],
            'ampersand term matches the raw character' => ['AT&T', ['t&t'], 'A<mark>T&amp;T</mark>'],
            'empty text' => ['', ['abc'], ''],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_highlights(string $text, array $terms, string $expected): void
    {
        $this->assertSame($expected, (new Highlighter)->highlight($text, $terms)->toHtml());
    }

    public function test_invalid_utf8_is_escaped_without_highlighting(): void
    {
        $html = (new Highlighter)->highlight("bad \xB1 bytes", ['bad'])->toHtml();

        $this->assertStringNotContainsString('<mark>', $html);
    }
}
