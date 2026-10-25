<?php

namespace Proteo\DrupalBackend\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drupal's change signal. The payload is ignored: any change moves the Source,
 * and the Sources it also flushes, to a new content generation.
 */
class DrupalWebhookController extends Controller
{
    public function __invoke(string $source, DrupalManager $drupal): Response
    {
        $flushed = $drupal->flush($source);

        Log::info('Drupal content cache flushed by webhook', ['sources' => $flushed]);

        return response(status: Response::HTTP_ACCEPTED);
    }
}
