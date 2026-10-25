<?php

namespace Proteo\DrupalBackend\Tests\Content;

use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Content\File;
use Proteo\DrupalBackend\Contract\ContractGenerator;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class FileRelationTest extends DescriptorTestCase
{
    use FakesDrupal;

    private Descriptor $descriptor;

    protected function drupalDescriptor(): Descriptor
    {
        return $this->descriptor;
    }

    /**
     * A `document` media Facet with a file relation, reached from a talk through `documents`.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = $this->fixture('conference');
        $config['content_types']['document'] = [
            'role' => 'facet',
            'entity_type' => 'media',
            'bundle' => 'document',
            'fields' => ['name' => ['source' => 'name', 'required' => true]],
            'relations' => ['file' => ['type' => 'file', 'via' => 'field_media_document']],
        ];
        $config['content_types']['talk']['relations']['documents'] = ['to' => 'document', 'via' => 'field_documents', 'many' => true];
        $config['content_types']['talk']['includes']['item'][] = 'documents.file';

        return $config;
    }

    private function mapper(array $config): ContentMapper
    {
        $this->descriptor = $this->descriptor($config);

        return new ContentMapper($this->descriptor, new EntityFactory($this->descriptor), 'https://app.test');
    }

    public function test_the_file_behind_a_media_entity_maps_to_a_file(): void
    {
        $mapper = $this->mapper($this->config());
        $entity = $this->fakeEntity('talk', related: ['documents' => [$this->fakeEntity('document', related: ['file' => ['uri' => '/files/talk.pdf', 'name' => 'Talk.PDF', 'mime' => 'application/pdf', 'size' => 1468006]])]]);

        $talk = $mapper->map('talk', $entity[0], new JsonApiDocument($this->fakeItem($entity)));
        $file = $talk->documents[0]->file;

        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('https://drupal.test/files/talk.pdf', $file->url);
        $this->assertSame(['Talk.PDF', 'application/pdf', 1468006, 'pdf', '1.4 MB'], [$file->name, $file->mime, $file->size, $file->extension(), $file->humanSize()]);
    }

    public function test_a_missing_file_or_empty_uri_is_null(): void
    {
        $mapper = $this->mapper($this->config());

        $none = $this->fakeEntity('document', related: ['file' => null]);
        $this->assertNull($mapper->map('document', $none[0], new JsonApiDocument($this->fakeItem($none)))->file);

        $empty = $this->fakeEntity('document', related: ['file' => ['uri' => '']]);
        $this->assertNull($mapper->map('document', $empty[0], new JsonApiDocument($this->fakeItem($empty)))->file);
    }

    public function test_file_helpers_cope_with_unknown_values(): void
    {
        $file = new File('https://x.test/f');

        $this->assertNull($file->extension());
        $this->assertNull($file->humanSize());
        $this->assertSame('512 B', (new File('u', size: 512))->humanSize());
    }

    public function test_a_dotted_include_expands_one_level(): void
    {
        $talk = $this->descriptor($this->config())->contentType('talk');

        $this->assertSame('field_documents.field_media_document', $talk->includeOf(['documents.file']));
        $this->assertStringContainsString('field_documents.field_media_document', $talk->include('item'));
        $this->assertSame('field_documents', $talk->includeOf(['documents']));
    }

    public function test_dotted_includes_are_validated_per_segment(): void
    {
        $config = $this->config();
        $config['content_types']['talk']['includes']['item'] = ['nope.file', 'documents.nope', 'speakers.name.x', 'cover.file'];

        $problems = $this->problems($config);

        $this->assertContains('content_types.talk.includes.item: unknown relation [nope] in [nope.file].', $problems);
        $this->assertContains('content_types.talk.includes.item: content type [document] has no relation [nope] in [documents.nope].', $problems);
        $this->assertContains('content_types.talk.includes.item: [speakers.name.x] goes more than one level deep.', $problems);
        $this->assertContains('content_types.talk.includes.item: [cover] in [cover.file] must be a content relation to follow it.', $problems);
    }

    public function test_the_validator_rejects_many_and_to_on_a_file_relation(): void
    {
        $config = $this->config();
        $config['content_types']['document']['relations']['file'] += ['many' => true, 'to' => 'talk', 'image_field' => 'x'];

        $problems = $this->problems($config);

        $this->assertContains('content_types.document.relations.file.many: a file relation holds one file.', $problems);
        $this->assertContains('content_types.document.relations.file.to: a file relation points at no content type.', $problems);
        $this->assertContains('content_types.document.relations.file.image_field: only an image relation has an image field.', $problems);
        $this->assertSame([], $this->problems($this->config()));
    }

    public function test_the_contract_describes_the_file_relation_and_its_hook(): void
    {
        $markdown = (new ContractGenerator($this->descriptor($this->config())))->markdown();

        $this->assertStringContainsString('file entity in `field_media_document` with `uri`, `filename`, `filemime`, `filesize`', $markdown);
        $this->assertStringContainsString('`file`', $markdown);
    }
}
