<?php

namespace Proteo\DrupalBackend\Descriptor;

final readonly class Relation
{
    /**
     * @param  ?string  $to  Target content type key; only for content relations.
     * @param  string  $via  Drupal relationship name, which is also the include path.
     * @param  ?string  $imageField  Field holding the file on the media entity of an image relation.
     */
    public function __construct(
        public string $name,
        public RelationKind $kind,
        public string $via,
        public ?string $to = null,
        public bool $many = false,
        public bool $optional = false,
        public ?string $imageField = null,
    ) {}

    /**
     * The include path that loads what the relation reads: an image needs its media entity's file too.
     */
    public function includePath(): string
    {
        return $this->kind === RelationKind::Image
            ? $this->via.'.'.($this->imageField ?? 'field_media_image')
            : $this->via;
    }
}
