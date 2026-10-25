<?php

namespace Proteo\DrupalBackend\View\Components;

use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Illuminate\View\View;
use Proteo\DrupalBackend\Search\Highlighter;

class Highlight extends Component
{
    /**
     * @param  list<string>  $terms  Sanitised words and phrases.
     */
    public function __construct(public string $text, public array $terms = []) {}

    public function render(): View
    {
        return view('drupal::components.highlight', [
            'highlighted' => $this->highlighted(),
        ]);
    }

    private function highlighted(): HtmlString
    {
        return app(Highlighter::class)->highlight($this->text, $this->terms);
    }
}
