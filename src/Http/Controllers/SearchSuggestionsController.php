<?php

namespace Proteo\DrupalBackend\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Proteo\DrupalBackend\Search\Suggestion;
use Proteo\DrupalBackend\Search\SuggestionFetcher;

/**
 * Suggestions for a simple search field, as `type`, `title`, `url` and `subtitle`.
 */
class SearchSuggestionsController extends Controller
{
    public function __invoke(Request $request, SuggestionFetcher $suggestions): JsonResponse
    {
        $term = $request->query('q');

        return response()
            ->json(array_map(
                fn (Suggestion $suggestion): array => [
                    'type' => $suggestion->type,
                    'title' => $suggestion->title,
                    'url' => $suggestion->url,
                    'subtitle' => $suggestion->subtitle,
                ],
                $suggestions->suggest(is_string($term) ? $term : ''),
            ))
            ->header('Cache-Control', 'no-store');
    }
}
