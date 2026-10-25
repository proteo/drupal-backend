<?php

namespace Proteo\DrupalBackend\Http;

use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\ProvidesSeo;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Search\PlainText;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Page metadata for an item: the entity's own, else from the type's `seo` map.
 */
class SeoFactory
{
    public function for(Entity $entity, ContentType $type): ?object
    {
        if ($entity instanceof ProvidesSeo) {
            return $entity->seo();
        }

        // ralphjsmit/laravel-seo is suggested, not required.
        if ($type->seo === null || ! class_exists(SEOData::class)) {
            return null;
        }

        $seo = $type->seo;

        return new SEOData(
            title: $this->text($entity, $seo->title),
            description: ($description = $this->text($entity, $seo->description)) === null
                ? null
                : PlainText::excerpt($description, $seo->descriptionMax),
            author: $this->text($entity, $seo->author),
            image: $this->text($entity, $seo->image),
            url: $entity->url(),
        );
    }

    /**
     * Only plain strings feed the metadata; a relation or missing field is left out.
     */
    private function text(Entity $entity, ?string $name): ?string
    {
        if ($name === null || ! $entity->has($name)) {
            return null;
        }

        $value = $entity->get($name);

        return is_string($value) && $value !== '' ? html_entity_decode($value) : null;
    }
}
