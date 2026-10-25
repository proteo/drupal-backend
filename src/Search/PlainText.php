<?php

namespace Proteo\DrupalBackend\Search;

use Illuminate\Support\Str;

/**
 * Reduces Drupal HTML to short plain text for compact search rows.
 */
final class PlainText
{
    public static function excerpt(string $html, int $limit = 200): string
    {
        // Tags become spaces first so adjoining paragraphs do not run together.
        $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html) ?? '');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($limit > 0) {
            $text = Str::limit($text, $limit);
        }

        return $text;
    }
}
