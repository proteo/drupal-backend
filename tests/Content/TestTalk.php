<?php

namespace Proteo\DrupalBackend\Tests\Content;

use Proteo\DrupalBackend\Content\Entity;

final readonly class TestTalk extends Entity
{
    public function decodedTitle(): string
    {
        return html_entity_decode($this->get('title'));
    }
}
