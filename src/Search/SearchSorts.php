<?php

namespace Proteo\DrupalBackend\Search;

use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;

/**
 * The orders offered per tab. `relevance` (only with a term) and `title` exist
 * everywhere; the rest are the type's own declared `sorts`. The backend
 * silently ignores unknown sort fields, so the names are validated here.
 */
final readonly class SearchSorts
{
    public const string RELEVANCE = 'relevance';

    public const string TITLE = 'title';

    public function __construct(private Descriptor $descriptor) {}

    /**
     * Whether the name is an order of any searched type.
     */
    public function isKnown(string $name): bool
    {
        foreach ($this->descriptor->search->tabs as $tab) {
            if (in_array($name, $this->names($this->descriptor->contentType($tab)), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Order names to offer on a tab, relevance first. Relevance needs a term.
     *
     * @return array<string, string> Name to label.
     */
    public function options(string $tab, bool $hasTerm): array
    {
        $options = [];

        foreach ($this->names($this->descriptor->contentType($tab)) as $name) {
            if ($hasTerm || $name !== self::RELEVANCE) {
                $options[$name] = $this->label($name);
            }
        }

        return $options;
    }

    /**
     * The order actually applied on a tab: one that does not fit the tab, or
     * relevance without a term, falls back to the tab's default. The
     * A type with declared sorts defaults to its first; the others use
     * relevance with a term and title without.
     */
    public function resolve(string $requested, string $tab, bool $hasTerm): string
    {
        // An unknown name is a stale or hand-written link: treat it as the plain default request.
        if (! $this->isKnown($requested)) {
            $requested = self::RELEVANCE;
        }

        if (array_key_exists($requested, $this->options($tab, $hasTerm))) {
            return $requested;
        }

        $type = $this->descriptor->contentType($tab);
        $first = array_key_first($type->sorts);

        if ($first !== null) {
            return $first;
        }

        return $hasTerm ? self::RELEVANCE : self::TITLE;
    }

    public function label(string $name): string
    {
        return match ($name) {
            self::RELEVANCE => 'Relevance',
            self::TITLE => 'Title A to Z',
            default => ucfirst(str_replace('_', ' ', $name)),
        };
    }

    /**
     * @return list<string>
     */
    private function names(ContentType $type): array
    {
        $names = [self::RELEVANCE, ...array_keys($type->sorts)];

        if (! in_array(self::TITLE, $names, true) && $type->titleAttribute() !== null) {
            $names[] = self::TITLE;
        }

        return $names;
    }
}
