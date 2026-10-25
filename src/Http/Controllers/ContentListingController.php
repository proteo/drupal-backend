<?php

namespace Proteo\DrupalBackend\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Proteo\DrupalBackend\Descriptor\Descriptor;

class ContentListingController extends Controller
{
    /**
     * The view hosts the paging component; the controller fetches nothing itself.
     */
    public function __invoke(string $contentType, Descriptor $descriptor): View
    {
        $type = $descriptor->contentType($contentType);

        return view()->first(array_values(array_filter([$type->views['index'] ?? null, $contentType.'.index', 'drupal::index'])), [
            'type' => $type,
        ]);
    }
}
