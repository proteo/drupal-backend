<?php

namespace Proteo\DrupalBackend\Tests\Content;

use Illuminate\Support\Facades\Log;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Content\Image;
use Proteo\DrupalBackend\Exceptions\DrupalBadResponse;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class ContentMapperTest extends DescriptorTestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function mapper(?array $config = null): ContentMapper
    {
        $descriptor = $this->descriptor($config ?? $this->fixture('conference'));

        return new ContentMapper($descriptor, new EntityFactory($descriptor), 'https://app.test');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationships
     * @return array<string, mixed>
     */
    private function talk(array $attributes = [], array $relationships = []): array
    {
        return [
            'type' => 'node--talk',
            'id' => 'b1',
            'attributes' => $attributes + ['title' => 'A talk', 'path' => '/a-talk', 'created' => '2024-05-01T00:00:00+00:00'],
            'relationships' => $relationships,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $included
     */
    private function document(array $talk, array $included = []): JsonApiDocument
    {
        return new JsonApiDocument(['data' => $talk, 'included' => $included]);
    }

    public function test_fields_are_mapped_under_their_frontend_names(): void
    {
        $talk = $this->talk(['short_description' => 'Short', 'review_reference' => 'rev-1']);

        $entity = $this->mapper()->map('talk', $talk, $this->document($talk));

        $this->assertSame('b1', $entity->id);
        $this->assertSame('/a-talk', $entity->path);
        $this->assertSame('A talk', $entity->title);
        $this->assertSame('2024-05-01T00:00:00+00:00', $entity->date);
        $this->assertSame('rev-1', $entity->review_id);
    }

    public function test_title_text_is_kept_as_delivered_and_html_is_decoded(): void
    {
        $talk = $this->talk(['title' => 'A &amp; B', 'description' => '&lt;p&gt;Body&lt;/p&gt;']);

        $entity = $this->mapper()->map('talk', $talk, $this->document($talk));

        $this->assertSame('A &amp; B', $entity->title);
        $this->assertSame('<p>Body</p>', $entity->content);
    }

    public function test_html_links_use_the_frontend_and_file_paths_the_backend(): void
    {
        $talk = $this->talk(['description' => '<a href="https://drupal.test/x"><img src="/sites/default/files/a.png"></a>']);

        $entity = $this->mapper()->map('talk', $talk, $this->document($talk));

        $this->assertSame('<a href="https://app.test/x"><img src="https://drupal.test/sites/default/files/a.png"></a>', $entity->content);
    }

    public function test_html_of_a_source_with_a_frontend_url_is_rewritten_to_it(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['review']['fields']['body'] = ['source' => 'body', 'type' => 'html'];
        $review = ['type' => 'node--article', 'id' => 'r1', 'attributes' => ['title' => 'Review', 'path' => '/review', 'body' => '<a href="https://reviews.test/r">r</a>']];

        $entity = $this->mapper($config)->map('review', $review, $this->document($review));

        $this->assertSame('<a href="https://reviews.example/r">r</a>', $entity->body);
    }

    public function test_an_empty_files_path_does_not_rewrite_every_attribute(): void
    {
        $config = $this->fixture('conference');
        $config['sources']['default']['files_path'] = '';
        $talk = $this->talk(['description' => '<a href="/x" class="y">z</a>']);

        $entity = $this->mapper($config)->map('talk', $talk, $this->document($talk));

        $this->assertSame('<a href="/x" class="y">z</a>', $entity->content);
    }

    public function test_missing_optional_fields_are_empty(): void
    {
        $talk = $this->talk();

        $entity = $this->mapper()->map('talk', $talk, $this->document($talk));

        $this->assertSame('', $entity->teaser);
        $this->assertNull($entity->review_id);
        $this->assertSame([], $entity->speakers);
        $this->assertNull($entity->cover);
    }

    public function test_content_relations_map_to_entities_of_the_target_type(): void
    {
        $talk = $this->talk([], [
            'speaker' => ['data' => [['type' => 'taxonomy_term--speaker', 'id' => 'a1']]],
            'tracks' => ['data' => [['type' => 'taxonomy_term--tracks', 'id' => 't1']]],
        ]);
        $document = $this->document($talk, [
            ['type' => 'taxonomy_term--speaker', 'id' => 'a1', 'attributes' => ['title' => 'Jane', 'path' => '/speaker/jane', 'talk_count' => '3', 'bio' => '&lt;p&gt;Bio&lt;/p&gt;']],
            ['type' => 'taxonomy_term--tracks', 'id' => 't1', 'attributes' => ['name' => 'Africa', 'path' => '/track/africa']],
        ]);

        $entity = $this->mapper()->map('talk', $talk, $document);

        $this->assertSame('speaker', $entity->speakers[0]->type);
        $this->assertSame('Jane', $entity->speakers[0]->name);
        $this->assertSame('<p>Bio</p>', $entity->speakers[0]->bio);
        $this->assertSame(3, $entity->speakers[0]->talk_count);
    }

    public function test_a_single_relation_is_one_entity_or_null(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['relations']['main_speaker'] = ['to' => 'speaker', 'via' => 'speaker'];
        $talk = $this->talk([], ['speaker' => ['data' => [['type' => 'taxonomy_term--speaker', 'id' => 'a1']]]]);
        $document = $this->document($talk, [['type' => 'taxonomy_term--speaker', 'id' => 'a1', 'attributes' => ['title' => 'Jane', 'path' => '/speaker/jane']]]);

        $entity = $this->mapper($config)->map('talk', $talk, $document);

        $this->assertSame('Jane', $entity->main_speaker->name);
        $this->assertNull($this->mapper($config)->map('talk', $this->talk(), $this->document($this->talk()))->main_speaker);
    }

    public function test_image_and_paragraph_relations_map(): void
    {
        $talk = $this->talk([], [
            'recording_resources' => ['data' => [['type' => 'paragraph--resource', 'id' => 'l1']]],
            'cover' => ['data' => ['type' => 'media--image', 'id' => 'm1']],
        ]);
        $document = $this->document($talk, [
            ['type' => 'paragraph--resource', 'id' => 'l1', 'attributes' => ['url' => 'https://www.shop.test/talk']],
            ['type' => 'media--image', 'id' => 'm1', 'attributes' => ['caption' => '&lt;em&gt;C&lt;/em&gt;'], 'relationships' => ['field_media_image' => ['data' => ['type' => 'file--file', 'id' => 'f1', 'meta' => ['alt' => 'Alt', 'width' => '10', 'height' => 20]]]]],
            ['type' => 'file--file', 'id' => 'f1', 'attributes' => ['uri' => '/sites/default/files/c.jpg', 'image_style_uri' => ['medium' => 'https://drupal.test/m.jpg', 'x' => '']]],
        ]);

        $entity = $this->mapper()->map('talk', $talk, $document);

        $this->assertSame('https://www.shop.test/talk', $entity->recording_resources[0]->href);
        $this->assertNull($entity->recording_resources[0]->path);
        $this->assertEquals(
            new Image('https://drupal.test/sites/default/files/c.jpg', 'Alt', 10, 20, '<em>C</em>', ['medium' => 'https://drupal.test/m.jpg']),
            $entity->cover,
        );
    }

    public function test_missing_required_field_id_or_path_is_a_bad_response(): void
    {
        foreach ([['title' => ''], ['path' => null]] as $attributes) {
            $talk = $this->talk($attributes);

            try {
                $this->mapper()->map('talk', $talk, $this->document($talk));
                $this->fail('Expected a bad response for '.json_encode($attributes));
            } catch (DrupalBadResponse) {
                $this->addToAssertionCount(1);
            }
        }

        $talk = $this->talk();
        unset($talk['id']);
        $this->expectException(DrupalBadResponse::class);
        $this->mapper()->map('talk', $talk, $this->document($talk));
    }

    public function test_a_broken_required_relation_fails_the_page(): void
    {
        $talk = $this->talk([], ['speaker' => ['data' => [['type' => 'taxonomy_term--speaker', 'id' => 'a1']]]]);
        $document = $this->document($talk, [['type' => 'taxonomy_term--speaker', 'id' => 'a1', 'attributes' => ['title' => '', 'path' => '/speaker/x']]]);

        $this->expectException(DrupalBadResponse::class);

        $this->mapper()->map('talk', $talk, $document);
    }

    public function test_a_broken_optional_relation_is_dropped_and_logged(): void
    {
        Log::spy();
        $config = $this->fixture('conference');
        $config['content_types']['talk']['relations']['speakers']['optional'] = true;
        $talk = $this->talk([], ['speaker' => ['data' => [['type' => 'taxonomy_term--speaker', 'id' => 'a1'], ['type' => 'taxonomy_term--speaker', 'id' => 'a2']]]]);
        $document = $this->document($talk, [
            ['type' => 'taxonomy_term--speaker', 'id' => 'a1', 'attributes' => ['title' => '', 'path' => '/speaker/x']],
            ['type' => 'taxonomy_term--speaker', 'id' => 'a2', 'attributes' => ['title' => 'Jane', 'path' => '/speaker/jane']],
        ]);

        $entity = $this->mapper($config)->map('talk', $talk, $document);

        $this->assertSame(['Jane'], array_map(fn ($speaker) => $speaker->name, $entity->speakers));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_type_with_an_alias_still_requires_its_path(): void
    {
        $talk = $this->talk();
        unset($talk['attributes']['path']);

        $this->expectException(DrupalBadResponse::class);

        $this->mapper()->map('talk', $talk, $this->document($talk));
    }

    public function test_a_required_field_of_a_related_paragraph_is_broken_data(): void
    {
        $talk = $this->talk([], ['slides_resources' => ['data' => [['type' => 'paragraph--resource', 'id' => 'l1']]]]);
        $document = $this->document($talk, [['type' => 'paragraph--resource', 'id' => 'l1', 'attributes' => []]]);

        $this->expectException(DrupalBadResponse::class);

        $this->mapper()->map('talk', $talk, $document);
    }

    public function test_a_reference_type_fails_soft(): void
    {
        Log::spy();
        $review = ['type' => 'node--article', 'id' => 'r1', 'attributes' => ['title' => '', 'path' => '/r']];

        $this->assertNull($this->mapper()->mapOrNull('review', $review, $this->document($review)));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_primary_does_not_fail_soft(): void
    {
        $talk = $this->talk(['title' => '']);

        $this->expectException(DrupalBadResponse::class);

        $this->mapper()->mapOrNull('talk', $talk, $this->document($talk));
    }

    public function test_a_reference_excerpt_is_plain_text_of_the_declared_length(): void
    {
        $review = ['type' => 'node--article', 'id' => 'r1', 'attributes' => ['title' => 'T', 'path' => '/r', 'teaser' => '<p>'.str_repeat('word ', 100).'</p>']];

        $entity = $this->mapper()->map('review', $review, $this->document($review));

        $this->assertLessThanOrEqual(303, strlen($entity->teaser));
        $this->assertStringStartsWith('word word', $entity->teaser);
    }

    public function test_a_relation_that_loops_back_stops_at_the_resource_being_mapped(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['speaker']['relations']['talks'] = ['to' => 'talk', 'via' => 'talks', 'many' => true];
        $talk = $this->talk([], ['speaker' => ['data' => [['type' => 'taxonomy_term--speaker', 'id' => 'a1']]]]);
        $document = $this->document($talk, [
            ['type' => 'taxonomy_term--speaker', 'id' => 'a1', 'attributes' => ['title' => 'Jane', 'path' => '/speaker/jane'], 'relationships' => ['talks' => ['data' => [['type' => 'node--talk', 'id' => 'b1']]]]],
            $talk,
        ]);

        $entity = $this->mapper($config)->map('talk', $talk, $document);

        $this->assertSame([], $entity->speakers[0]->talks);
    }

    public function test_the_project_subclass_named_in_the_descriptor_is_built(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['entity'] = TestTalk::class;
        $talk = $this->talk();

        $this->assertInstanceOf(TestTalk::class, $this->mapper($config)->map('talk', $talk, $this->document($talk)));
    }
}
