<?php

namespace Proteo\DrupalBackend\Tests\Descriptor;

use PHPUnit\Framework\TestCase;
use Proteo\DrupalBackend\Descriptor\DescriptorSchema;

class DescriptorSchemaTest extends TestCase
{
    public function test_the_fixtures_have_no_unknown_keys(): void
    {
        foreach (['conference', 'article'] as $name) {
            $this->assertSame([], DescriptorSchema::unknownKeys(require __DIR__.'/../Fixtures/'.$name.'.php'), $name);
        }
    }

    public function test_a_misspelled_key_is_reported_with_a_suggestion(): void
    {
        $config = require __DIR__.'/../Fixtures/conference.php';
        $config['content_types']['talk']['listing']['per_pge'] = 18;
        $config['content_types']['talk']['per_pge'] = 18;
        $config['search']['term']['mn'] = 2;

        $this->assertSame([
            'content_types.talk.listing: unknown key.',
            'content_types.talk.per_pge: unknown key; did you mean [per_page]?',
            'search.term.mn: unknown key; did you mean [min]?',
        ], DescriptorSchema::unknownKeys($config));
    }

    public function test_user_chosen_names_are_not_unknown(): void
    {
        $config = ['sources' => ['anything' => ['base_url' => 'x']], 'content_types' => ['whatever' => ['fields' => ['any' => ['source' => 'a']]]]];

        $this->assertSame([], DescriptorSchema::unknownKeys($config));
    }

    public function test_sections_list_keys_relative_to_their_table(): void
    {
        $sections = DescriptorSchema::sections();

        $this->assertContains('native_limit', $sections['search']);
        $this->assertContains('term.min', $sections['search']);
        $this->assertContains('webhook.secret', $sections['sources.*']);
        $this->assertContains('fields', $sections['content_types.*']);
        $this->assertContains('per_page', $sections['content_types.*']);
        $this->assertContains('sources', $sections['']);
        $this->assertContains('type', $sections['content_types.*.filters.*']);
        $this->assertNotContains('native_limit', $sections['content_types.*']);
    }
}
