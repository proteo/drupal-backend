<?php

namespace Proteo\DrupalBackend\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

class SearchPageController extends Controller
{
    /**
     * The view hosts the search component; the controller fetches nothing itself.
     */
    public function __invoke(): View
    {
        return view()->first(['search', 'drupal::search']);
    }
}
