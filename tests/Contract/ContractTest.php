<?php

namespace Proteo\DrupalBackend\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Proteo\DrupalBackend\Contract\ContractGenerator;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class ContractTest extends DescriptorTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtures(): array
    {
        return ['conference' => ['conference'], 'article' => ['article']];
    }

    private function markdown(array $config): string
    {
        return (new ContractGenerator($this->descriptor($config)))->markdown();
    }

    /**
     * Run with UPDATE_SNAPSHOTS=1 after an intended descriptor or generator change.
     */
    #[DataProvider('fixtures')]
    public function test_matches_the_committed_snapshot(string $fixture): void
    {
        $path = __DIR__."/snapshots/$fixture.md";
        $actual = $this->markdown($this->fixture($fixture));

        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            file_put_contents($path, $actual);
        }

        $this->assertSame(file_get_contents($path), $actual);
    }

    public function test_the_payload_is_described_as_the_changes_envelope(): void
    {
        $markdown = $this->markdown($this->fixture('conference'));

        $this->assertStringContainsString('Payload v2: `{"changes": [{"event"', $markdown);
    }

    public function test_a_descriptor_change_changes_the_contract(): void
    {
        $config = $this->fixture('conference');
        $before = $this->markdown($config);

        $config['content_types']['talk']['filters']['topic']['native_path'] = 'renamed.id';

        $this->assertNotSame($before, $this->markdown($config));
    }

    public function test_hooks_cover_entity_types_behind_image_relations(): void
    {
        $markdown = $this->markdown($this->fixture('conference'));

        $this->assertStringContainsString('`file`, `media`, `node`, `paragraph`, `taxonomy_term`', $markdown);
    }

    public function test_search_requirements_are_left_out_when_search_is_off(): void
    {
        $config = $this->fixture('conference');
        $config['search']['enabled'] = false;

        $this->assertStringNotContainsString('Search API', $this->markdown($config));
    }

    public function test_the_command_prints_the_contract(): void
    {
        $this->app->instance(Descriptor::class, $this->descriptor($this->fixture('conference')));

        $this->artisan('drupal:contract')->expectsOutputToContain('# Drupal contract')->assertSuccessful();
    }
}
