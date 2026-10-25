<?php

namespace Proteo\DrupalBackend\Search;

use Illuminate\Support\HtmlString;

/**
 * Marks search terms in plain text and returns safe markup.
 *
 * Matching rule (shared with the suggestions script): substring,
 * case-insensitive, accents significant, longer and overlapping matches merged.
 */
final class Highlighter
{
    public const int MIN_TERM_LENGTH = 2;

    /**
     * @param  string  $text  Plain text, never markup.
     * @param  list<string>  $terms  Words and phrases from the TermSanitiser.
     */
    public function highlight(string $text, array $terms): HtmlString
    {
        $segments = [];
        $offset = 0;

        foreach ($this->matches($text, $terms) as [$start, $end]) {
            $segments[] = e(substr($text, $offset, $start - $offset));
            $segments[] = '<mark>'.e(substr($text, $start, $end - $start)).'</mark>';
            $offset = $end;
        }

        // Every segment is escaped on its own; nothing is substituted into escaped output.
        $segments[] = e(substr($text, $offset));

        return new HtmlString(implode('', $segments));
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{int, int}> Merged, ordered byte ranges.
     */
    private function matches(string $text, array $terms): array
    {
        $ranges = [];

        foreach (array_unique($terms) as $term) {
            if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
                continue;
            }

            // False on invalid UTF-8: show the text unmarked rather than fail.
            if (preg_match_all('/'.preg_quote($term, '/').'/iu', $text, $found, PREG_OFFSET_CAPTURE) === false) {
                return [];
            }

            foreach ($found[0] as [$match, $start]) {
                $ranges[] = [$start, $start + strlen($match)];
            }
        }

        usort($ranges, fn (array $a, array $b): int => [$a[0], $b[1]] <=> [$b[0], $a[1]]);

        $merged = [];

        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start < $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }
}
