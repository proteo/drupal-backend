<?php

namespace Proteo\DrupalBackend\Tests\Content;

use LogicException;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Content\Image;
use Proteo\DrupalBackend\Content\UnknownAttribute;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class EntityTest extends DescriptorTestCase
{
    private Descriptor $descriptor;

    private EntityFactory $entities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->descriptor = $this->descriptor($this->fixture('conference'));
        $this->entities = new EntityFactory($this->descriptor);
    }

    private function talk(array $values = []): Entity
    {
        return $this->entities->make('talk', 'b1', '/a-talk', $values + ['title' => 'A talk', 'date' => '2024-05-01T00:00:00+00:00']);
    }

    public function test_fields_are_read_by_their_frontend_names(): void
    {
        $talk = $this->talk(['teaser' => '<p>Short</p>']);

        $this->assertSame('A talk', $talk->title);
        $this->assertSame('A talk', $talk->get('title'));
        $this->assertSame('<p>Short</p>', $talk->teaser);
    }

    public function test_relations_and_value_objects_are_returned_as_given(): void
    {
        $speaker = $this->entities->make('speaker', 'a1', '/speaker/jane', ['name' => 'Jane']);
        $link = $this->entities->make('resource', 'l1', null, ['href' => 'https://www.shop.test/talk']);
        $cover = new Image('https://drupal.test/cover.jpg');

        $talk = $this->talk(['speakers' => [$speaker], 'slide_resources' => [$link], 'cover' => $cover]);

        $this->assertSame([$speaker], $talk->speakers);
        $this->assertSame('Jane', $talk->speakers[0]->name);
        $this->assertSame($link, $talk->get('slide_resources')[0]);
        $this->assertSame($cover, $talk->cover);
    }

    public function test_an_entity_without_a_path_has_no_url(): void
    {
        $link = $this->entities->make('resource', 'l1', null, ['href' => 'https://www.shop.test/talk']);

        $this->assertNull($link->path);
        $this->expectException(LogicException::class);
        $link->url();
    }

    public function test_a_declared_name_missing_from_the_payload_is_null(): void
    {
        $talk = $this->talk();

        $this->assertNull($talk->teaser);
        $this->assertNull($talk->cover);
    }

    public function test_an_unknown_name_throws(): void
    {
        $talk = $this->talk();

        $this->expectException(UnknownAttribute::class);
        $this->expectExceptionMessage('Content type [talk] has no field or relation named [titel]');

        $talk->titel;
    }

    public function test_get_throws_for_an_unknown_name_too(): void
    {
        $this->expectException(UnknownAttribute::class);

        $this->talk()->get('subtitle');
    }

    public function test_the_built_ins(): void
    {
        $talk = $this->talk();

        $this->assertSame('b1', $talk->id);
        $this->assertSame('talk', $talk->type);
        $this->assertSame('/a-talk', $talk->path);
        $this->assertSame('talk', $talk->get('type'));
    }

    public function test_url_joins_the_url_prefix_and_the_alias(): void
    {
        $this->assertSame(url('talk/a-talk'), $this->talk()->url());
    }

    public function test_url_strips_the_alias_prefix(): void
    {
        $topic = $this->entities->make('topic', 'a1', '/topic/php', ['name' => 'PHP']);

        $this->assertSame(url('topic/php'), $topic->url());
    }

    public function test_a_page_without_a_url_prefix_is_served_at_its_alias(): void
    {
        $page = $this->entities->make('page', 'p1', '/about', ['title' => 'About']);

        $this->assertSame(url('about'), $page->url());
    }

    public function test_count_is_not_a_built_in_name(): void
    {
        $this->expectException(UnknownAttribute::class);

        $this->talk()->count;
    }

    public function test_isset_follows_php_semantics_without_throwing(): void
    {
        $talk = $this->talk(['teaser' => 'x']);

        $this->assertTrue(isset($talk->title));
        $this->assertTrue(isset($talk->teaser));
        $this->assertFalse(isset($talk->cover));
        $this->assertFalse(isset($talk->nonsense));
        $this->assertSame('fallback', $talk->cover ?? 'fallback');
    }

    public function test_has_reports_declared_names(): void
    {
        $talk = $this->talk();

        $this->assertTrue($talk->has('cover'));
        $this->assertTrue($talk->has('speakers'));
        $this->assertFalse($talk->has('nonsense'));
        $this->assertFalse($talk->has('count'));
    }

    public function test_entities_are_immutable(): void
    {
        $talk = $this->talk();

        $this->expectException(LogicException::class);

        $talk->title = 'Changed';
    }

    public function test_values_cannot_reuse_a_built_in_name(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('built-in names [path]');

        $this->entities->make('talk', 'b1', '/a-talk', ['path' => 'x']);
    }

    public function test_the_factory_uses_the_subclass_named_in_the_descriptor(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['entity'] = TestTalk::class;
        $entities = new EntityFactory($this->descriptor($config));

        $talk = $entities->make('talk', 'b1', '/a-talk', ['title' => 'Tom &amp; Jerry']);

        $this->assertInstanceOf(TestTalk::class, $talk);
        $this->assertSame('Tom & Jerry', $talk->decodedTitle());
        $this->assertSame('Tom &amp; Jerry', $talk->title);
        $this->assertNotInstanceOf(TestTalk::class, $this->entities->make('speaker', 'a1', '/speaker/x', ['name' => 'X']));
    }

    public function test_the_factory_rejects_an_unknown_content_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->entities->make('movie', 'm1', '/m');
    }
}
