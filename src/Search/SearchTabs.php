<?php

namespace Proteo\DrupalBackend\Search;

use Illuminate\Support\Str;
use Proteo\DrupalBackend\Descriptor\Descriptor;

/**
 * The result groups of the search, in display order: the descriptor's `search.tabs`.
 */
final readonly class SearchTabs
{
    public function __construct(private Descriptor $descriptor) {}

    /**
     * @return list<string> Content type keys.
     */
    public function keys(): array
    {
        return $this->descriptor->search->tabs;
    }

    /**
     * The first tab is the default one: unknown values fall back to it.
     */
    public function resolve(string $requested): string
    {
        return in_array($requested, $this->keys(), true) ? $requested : $this->default();
    }

    public function default(): string
    {
        return $this->keys()[0] ?? '';
    }

    public function label(string $key): string
    {
        return Str::plural(Str::headline($key));
    }
}
