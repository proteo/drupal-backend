<?php

namespace Proteo\DrupalBackend\Livewire\Forms;

use Closure;
use Livewire\Attributes\Url;
use Livewire\Form;
use Proteo\DrupalBackend\Search\SearchSorts;
use Proteo\DrupalBackend\Search\TermSanitiser;

class SearchForm extends Form
{
    #[Url(as: 'q', history: false)]
    public string $term = '';

    #[Url(as: 'sort', history: false)]
    public string $sort = SearchSorts::RELEVANCE;

    /**
     * Follows `search.term.min`, so the message and the rule cannot disagree.
     */
    public static function tooShortMessage(): string
    {
        return sprintf('Enter at least %d letters or numbers.', app(TermSanitiser::class)->minLength());
    }

    /**
     * The term as shown on the page: trimmed and capped, never altered otherwise.
     */
    public function displayTerm(): string
    {
        return mb_substr(trim($this->term), 0, app(TermSanitiser::class)->maxLength());
    }

    /**
     * The order for a tab; an unknown key means the default.
     */
    public function sortOrder(string $tab, bool $hasTerm = true): string
    {
        return app(SearchSorts::class)->resolve($this->sort, $tab, $hasTerm);
    }

    public function hasUnknownSort(): bool
    {
        return ! app(SearchSorts::class)->isKnown($this->sort);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'term' => [
                'string',
                // Empty terms skip this rule: they mean "no search yet", not an error.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(TermSanitiser::class)->sanitise((string) $value)->isUsable()) {
                        $fail(self::tooShortMessage());
                    }
                },
            ],
        ];
    }
}
