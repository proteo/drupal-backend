<?php

namespace Proteo\DrupalBackend\Search;

use Proteo\DrupalBackend\Descriptor\SearchConfig;

/**
 * Turns the term as typed into the fulltext value and the terms to highlight.
 *
 * Applies only when building the upstream query; the URL and the displayed
 * term keep what the visitor typed.
 */
final readonly class TermSanitiser
{
    /** Lucene reserved characters, which would otherwise be read as operators. */
    private const string RESERVED = '/[+\-!(){}\[\]^~*?:\\\\\/&|]/u';

    public function __construct(private SearchConfig $config = new SearchConfig) {}

    public function minLength(): int
    {
        return $this->config->termMin;
    }

    public function maxLength(): int
    {
        return $this->config->termMax;
    }

    public function sanitise(string $input): SanitisedTerm
    {
        // Truncate first: a cut inside a phrase is repaired by the quote check below.
        $input = mb_substr(trim($input), 0, $this->config->termMax);

        if (substr_count($input, '"') % 2 === 1) {
            $input = $this->withoutLastQuote($input);
        }

        preg_match_all('/"([^"]*)"|[^\s"]+/u', $input, $tokens, PREG_SET_ORDER);

        $fulltext = [];
        $terms = [];
        $remaining = $this->config->termMaxWords;

        foreach ($tokens as $token) {
            if ($remaining === 0) {
                break;
            }

            $isPhrase = isset($token[1]);
            // Lowercasing neutralises AND/OR/NOT, which stay in as ordinary words.
            $words = preg_split('/\s+/u', mb_strtolower(preg_replace(self::RESERVED, ' ', $token[1] ?? $token[0])), -1, PREG_SPLIT_NO_EMPTY);
            $words = array_slice($words, 0, $remaining);

            if ($words === []) {
                continue;
            }

            $remaining -= count($words);

            if ($isPhrase) {
                $phrase = implode(' ', $words);
                $fulltext[] = '"'.$phrase.'"';
                $terms[] = $phrase;

                continue;
            }

            foreach ($words as $word) {
                $fulltext[] = $word;
                $terms[] = $word;
            }
        }

        $letters = mb_strlen(preg_replace('/\s+/u', '', implode('', $terms)));

        if ($letters < $this->config->termMin) {
            return new SanitisedTerm;
        }

        return new SanitisedTerm(implode(' ', $fulltext), $terms);
    }

    private function withoutLastQuote(string $input): string
    {
        $position = strrpos($input, '"');

        return substr($input, 0, $position).substr($input, $position + 1);
    }
}
