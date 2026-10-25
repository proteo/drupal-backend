<?php

namespace Proteo\DrupalBackend\Tests\Descriptor;

use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\DescriptorFactory;
use Proteo\DrupalBackend\Descriptor\DescriptorLoader;
use Proteo\DrupalBackend\Descriptor\DescriptorValidator;
use Proteo\DrupalBackend\Descriptor\InvalidDescriptor;
use Proteo\DrupalBackend\DrupalBackendServiceProvider;
use Psr\Log\AbstractLogger;

class DescriptorLoaderTest extends DescriptorTestCase
{
    /** @var list<string> */
    private array $errors = [];

    private function loader(bool $production): DescriptorLoader
    {
        $log = new class($this->errors) extends AbstractLogger
        {
            /** @param list<string> $errors */
            public function __construct(private array &$errors) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = $level.': '.$message;
            }
        };

        return new DescriptorLoader(new DescriptorFactory, new DescriptorValidator, $log, $production);
    }

    /**
     * @return array<string, mixed>
     */
    private function broken(): array
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['fields']['id'] = ['source' => 'x'];
        $config['content_types']['talk']['includes']['item'] = ['ghosts'];

        return $config;
    }

    public function test_outside_production_a_broken_descriptor_throws_with_every_problem(): void
    {
        try {
            $this->loader(production: false)->load($this->broken());
            $this->fail('Expected InvalidDescriptor.');
        } catch (InvalidDescriptor $e) {
            $this->assertStringContainsString('2 problem(s)', $e->getMessage());
            $this->assertStringContainsString('fields.id', $e->getMessage());
            $this->assertStringContainsString('unknown relation [ghosts]', $e->getMessage());
        }
    }

    public function test_in_production_problems_are_logged_and_the_descriptor_is_still_returned(): void
    {
        $descriptor = $this->loader(production: true)->load($this->broken());

        $this->assertInstanceOf(Descriptor::class, $descriptor);
        $this->assertCount(2, $this->errors);
        $this->assertStringContainsString('error: Invalid drupal-backend descriptor: content_types.talk.fields.id', $this->errors[0]);
    }

    public function test_a_sound_descriptor_logs_nothing(): void
    {
        $this->loader(production: true)->load($this->fixture('conference'));

        $this->assertSame([], $this->errors);
    }

    public function test_a_schema_mismatch_fails_even_in_production(): void
    {
        $config = $this->fixture('conference');
        $config['schema_version'] = 9;

        $this->expectException(InvalidDescriptor::class);

        $this->loader(production: true)->load($config);
    }

    public function test_the_container_builds_the_descriptor_from_config(): void
    {
        config(['drupal-backend' => $this->fixture('article')]);

        $descriptor = $this->app->make(Descriptor::class);

        $this->assertSame(['article', 'writer', 'topic', 'genre'], array_keys($descriptor->contentTypes));
        $this->assertSame($descriptor, $this->app->make(Descriptor::class));
    }

    public function test_booting_with_a_broken_descriptor_stops_the_app(): void
    {
        config(['drupal-backend' => $this->broken()]);

        $this->expectException(InvalidDescriptor::class);

        (new DrupalBackendServiceProvider($this->app))->boot();
    }

    public function test_booting_without_content_types_stays_inert(): void
    {
        config(['drupal-backend.content_types' => []]);

        (new DrupalBackendServiceProvider($this->app))->boot();

        $this->assertFalse($this->app->resolved(Descriptor::class));
    }
}
