<?php

namespace Proteo\DrupalBackend\Content;

/**
 * A downloadable file as the templates need it, read from a file entity.
 * Distinct from Image, which also carries styles and dimensions.
 */
final readonly class File
{
    public function __construct(
        public string $url,
        public ?string $name = null,
        public ?string $mime = null,
        public ?int $size = null,
    ) {}

    /**
     * The lowercase extension of the file name, without the dot.
     */
    public function extension(): ?string
    {
        $extension = $this->name === null ? '' : pathinfo($this->name, PATHINFO_EXTENSION);

        return $extension === '' ? null : strtolower($extension);
    }

    /**
     * The size for people, such as "1.4 MB"; null when the size is unknown.
     */
    public function humanSize(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->size;

        for ($unit = 0; $size >= 1024 && $unit < count($units) - 1; $unit++) {
            $size /= 1024;
        }

        return ($unit === 0 ? (string) $this->size : number_format($size, 1, '.', '')).' '.$units[$unit];
    }
}
