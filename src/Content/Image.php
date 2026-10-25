<?php

namespace Proteo\DrupalBackend\Content;

/**
 * An image as the templates need it. Dimensions describe the original only:
 * Drupal does not expose the dimensions of a derivative.
 */
final readonly class Image
{
    /**
     * @param  array<string, string>  $styles  Derivative URLs keyed by image style name.
     */
    public function __construct(
        public ?string $src,
        public ?string $alt = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $caption = null,
        public array $styles = [],
    ) {}

    public function hasStyle(?string $size): bool
    {
        return $size !== null && $size !== '' && ($this->styles[$size] ?? '') !== '';
    }

    /**
     * The URL of the requested style, or the original when no size is given
     * or Drupal does not offer that style.
     */
    public function urlFor(?string $size = null): ?string
    {
        return $this->hasStyle($size) ? $this->styles[$size] : $this->src;
    }
}
