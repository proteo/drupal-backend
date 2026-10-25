<?php

namespace Proteo\DrupalBackend\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

/**
 * A failed interaction with the Drupal backend.
 *
 * Messages are generic on purpose: everything about the upstream (URL, status,
 * duration) lives in context() and so only reaches the logs.
 */
abstract class DrupalException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $upstream  url, status, duration_ms, attempts
     */
    public function __construct(
        private readonly array $upstream = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($this->publicMessage(), 0, $previous);
    }

    abstract public function status(): int;

    abstract protected function publicMessage(): string;

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->upstream;
    }

    public function render(Request $request): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], $this->status(), $this->headers());
        }

        // The project's own error page first, so a fresh app needs no views of its own.
        $view = view()->first(['errors.'.$this->status(), 'drupal::error'], ['status' => $this->status(), 'message' => $this->getMessage()])->name();

        return response()->view($view, ['status' => $this->status(), 'message' => $this->getMessage()], $this->status(), $this->headers());
    }
}
