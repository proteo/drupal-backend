<?php

namespace Proteo\DrupalBackend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts only requests signed by Drupal.
 *
 * The signature is the hex HMAC-SHA256 of "{timestamp}.{raw body}" with the
 * shared secret, so the timestamp is covered and cannot be refreshed on a
 * captured request. Headers: X-Drupal-Timestamp, X-Drupal-Signature.
 */
class VerifyDrupalWebhook
{
    public function __construct(private readonly Descriptor $descriptor) {}

    /**
     * The route names the Source whose secret signs the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $source = $this->descriptor->sources[(string) $request->route('source')] ?? null;
        $secret = (string) $source?->webhookSecret;
        $tolerance = $source?->webhookTolerance ?? $this->descriptor->webhook->tolerance;

        // Inert until configured, so the endpoint is not even discoverable.
        if ($secret === '') {
            $this->reject($request, 404, 'secret_not_configured');
        }

        $timestamp = (string) $request->header('X-Drupal-Timestamp');
        $signature = (string) $request->header('X-Drupal-Signature');

        $withinTolerance = ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= $tolerance;

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! $withinTolerance) {
            $this->reject($request, 401, 'timestamp_invalid_or_outside_tolerance', ['timestamp' => $timestamp]);
        }

        if (! hash_equals($expected, $signature)) {
            $this->reject($request, 401, 'signature_mismatch');
        }

        // A signature is accepted once; remembering it for twice the tolerance outlives its validity.
        if (! Cache::add('drupal:webhook:'.$source->name.':'.$signature, true, $tolerance * 2)) {
            $this->reject($request, 401, 'replayed_signature');
        }

        return $next($request);
    }

    /**
     * Logs why the request was refused; the signature and secret are never logged.
     *
     * @param  array<string, mixed>  $context
     */
    private function reject(Request $request, int $status, string $reason, array $context = []): never
    {
        Log::warning('Drupal webhook rejected', ['reason' => $reason, 'status' => $status, 'ip' => $request->ip()] + $context);

        abort($status);
    }
}
