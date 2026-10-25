<?php

namespace Proteo\DrupalBackend\Tests\Content;

use Illuminate\Container\Container;
use LogicException;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\EntityDecorator;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;
use RuntimeException;

class RecordingDecorator implements EntityDecorator
{
    /**
     * @var list<list<string>> Entity ids per call.
     */
    public static array $calls = [];

    public function decorate(array $entities): array
    {
        self::$calls[] = array_map(fn (Entity $entity): string => $entity->id, $entities);

        return collect($entities)->mapWithKeys(fn (Entity $entity): array => [$entity->id => ['label' => 'x-'.$entity->id]])->all();
    }
}

class ThrowingDecorator implements EntityDecorator
{
    public function decorate(array $entities): array
    {
        throw new RuntimeException('remote down');
    }
}

class EntityDecoratorTest extends DescriptorTestCase
{
    use FakesDrupal;

    private Descriptor $descriptor;

    protected function drupalDescriptor(): Descriptor
    {
        return $this->descriptor;
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingDecorator::$calls = [];
    }

    /**
     * @param  array<string, string>  $decorators  Content type key to decorator class.
     */
    private function mapper(array $decorators): ContentMapper
    {
        $config = $this->fixture('conference');
        foreach ($decorators as $key => $class) {
            $config['content_types'][$key]['decorator'] = $class;
        }

        $this->descriptor = $this->descriptor($config);

        return new ContentMapper($this->descriptor, new EntityFactory($this->descriptor), 'https://app.test', Container::getInstance());
    }

    public function test_a_type_is_decorated_once_with_every_entity_of_the_response_nested_ones_included(): void
    {
        $mapper = $this->mapper(['talk' => RecordingDecorator::class, 'speaker' => RecordingDecorator::class]);
        $one = $this->fakeEntity('talk', ['id' => 'b1']);
        $two = $this->fakeEntity('talk', ['id' => 'b2']);
        $document = new JsonApiDocument($this->fakeCollection('talk', [$one, $two]));

        $talks = array_map(fn (array $resource): Entity => $mapper->map('talk', $resource, $document), $document->data());
        $mapper->decorate($document);

        $this->assertCount(2, RecordingDecorator::$calls);
        $this->assertContains(['b1', 'b2'], RecordingDecorator::$calls);
        $this->assertSame('x-b1', $talks[0]->extra('label'));
        $this->assertSame('x-b2', $talks[1]->extra('label'));
        $this->assertSame('x-'.$talks[0]->speakers[0]->id, $talks[0]->speakers[0]->extra('label'));
    }

    public function test_an_entity_appearing_twice_is_decorated_once_by_id(): void
    {
        $mapper = $this->mapper(['talk' => RecordingDecorator::class]);
        $entity = $this->fakeEntity('talk', ['id' => 'b1']);
        $document = new JsonApiDocument($this->fakeItem($entity));

        $mapper->map('talk', $document->data(), $document);
        $mapper->map('talk', $document->data(), $document);
        $mapper->decorate($document);

        $this->assertSame([['b1']], RecordingDecorator::$calls);
    }

    public function test_separate_responses_do_not_share_extras(): void
    {
        $mapper = $this->mapper(['talk' => RecordingDecorator::class]);
        $first = new JsonApiDocument($this->fakeItem($this->fakeEntity('talk', ['id' => 'b1'])));
        $second = new JsonApiDocument($this->fakeItem($this->fakeEntity('talk', ['id' => 'b2'])));

        $one = $mapper->map('talk', $first->data(), $first);
        $two = $mapper->map('talk', $second->data(), $second);
        $mapper->decorate($first);

        $this->assertSame('x-b1', $one->extra('label'));
        $this->assertNull($two->extra('label'));
        $this->assertFalse($two->hasExtra('label'));
    }

    public function test_an_id_the_decorator_skipped_reads_null(): void
    {
        $mapper = $this->mapper(['talk' => RecordingDecorator::class]);
        $document = new JsonApiDocument($this->fakeItem($this->fakeEntity('talk')));

        $talk = $mapper->map('talk', $document->data(), $document);
        $mapper->decorate($document);

        $this->assertNull($talk->extra('missing'));
        $this->assertFalse($talk->hasExtra('missing'));
        $this->assertTrue($talk->hasExtra('label'));
    }

    public function test_reading_an_extra_of_a_type_without_a_decorator_throws(): void
    {
        $mapper = $this->mapper([]);
        $document = new JsonApiDocument($this->fakeItem($this->fakeEntity('workshop')));
        $workshop = $mapper->map('workshop', $document->data(), $document);

        $this->expectException(LogicException::class);
        $workshop->extra('label');
    }

    public function test_a_throwing_decorator_propagates(): void
    {
        $mapper = $this->mapper(['talk' => ThrowingDecorator::class]);
        $document = new JsonApiDocument($this->fakeItem($this->fakeEntity('talk')));
        $mapper->map('talk', $document->data(), $document);

        $this->expectExceptionMessage('remote down');
        $mapper->decorate($document);
    }

    public function test_entities_stay_readonly(): void
    {
        $this->assertTrue((new \ReflectionClass(Entity::class))->isReadOnly());
    }

    public function test_validation_rejects_a_decorator_that_does_not_implement_the_interface(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['decorator'] = \stdClass::class;

        $this->assertContains(
            'content_types.talk.decorator: ['.\stdClass::class.'] must be a class implementing '.EntityDecorator::class.'.',
            $this->problems($config),
        );
    }
}
