<?php

namespace Proteo\DrupalBackend\Tests\Http;

use PHPUnit\Framework\TestCase;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\ProvidesSeo;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\DescriptorFactory;
use Proteo\DrupalBackend\Descriptor\SeoConfig;
use Proteo\DrupalBackend\Http\SeoFactory;
use RalphJSmit\Laravel\SEO\Support\SEOData;

class SeoFactoryTest extends TestCase
{
    private function talkType(?SeoConfig $seo = null): ContentType
    {
        $type = (new DescriptorFactory)->make(require __DIR__.'/../Fixtures/conference.php')->contentType('talk');

        return $seo === null ? $type : new ContentType(...[...get_object_vars($type), 'seo' => $seo]);
    }

    public function test_an_entity_that_provides_its_own_seo_takes_over(): void
    {
        $type = $this->talkType();
        $entity = new readonly class($type, 'id', '/talk/x') extends Entity implements ProvidesSeo
        {
            public function seo(): object
            {
                return (object) ['title' => 'Own'];
            }
        };

        $this->assertSame('Own', (new SeoFactory)->for($entity, $type)->title);
    }

    public function test_there_is_no_seo_without_a_map(): void
    {
        $type = $this->talkType();

        $this->assertNull((new SeoFactory)->for(new Entity($type, 'id', '/talk/x'), $type));
    }

    public function test_the_map_is_skipped_when_laravel_seo_is_absent(): void
    {
        if (class_exists(SEOData::class)) {
            $this->markTestSkipped('ralphjsmit/laravel-seo is installed.');
        }

        $type = $this->talkType(new SeoConfig(title: 'title'));
        $entity = new Entity($type, 'id', '/talk/x', ['title' => 'A talk']);

        $this->assertNull((new SeoFactory)->for($entity, $type));
    }
}
