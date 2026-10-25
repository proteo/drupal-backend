<?php

namespace Proteo\DrupalBackend\Tests\Contract;

use Proteo\DrupalBackend\Contract\ContractContributor;
use Proteo\DrupalBackend\Contract\ContractGenerator;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class ContractContributorTest extends DescriptorTestCase
{
    public function test_contributors_add_modules_type_lines_and_sections(): void
    {
        $contributor = new class implements ContractContributor
        {
            public function modules(): array
            {
                return ['Extra module'];
            }

            public function forContentType(ContentType $type): array
            {
                return $type->key === 'talk' ? ['- [ ] `extra_column`: index column'] : [];
            }

            public function sections(): array
            {
                return ["## Extra section\n\n- [ ] Something"];
            }
        };

        $markdown = (new ContractGenerator($this->descriptor($this->fixture('conference')), [$contributor]))->markdown();

        $this->assertStringContainsString('- [ ] Extra module', $markdown);
        $this->assertStringContainsString('- [ ] `extra_column`: index column', $markdown);
        $this->assertStringContainsString("## Extra section\n\n- [ ] Something", $markdown);
        $this->assertLessThan(strpos($markdown, '## Drupal changes'), strpos($markdown, '## Extra section'));
    }

    public function test_the_container_merges_services_tagged_for_the_contract(): void
    {
        $this->app->instance(Descriptor::class, $this->descriptor($this->fixture('conference')));
        $this->app->bind('test.contributor', fn () => new class implements ContractContributor
        {
            public function modules(): array
            {
                return ['Tagged module'];
            }

            public function forContentType(ContentType $type): array
            {
                return [];
            }

            public function sections(): array
            {
                return [];
            }
        });
        $this->app->tag(['test.contributor'], 'drupal-backend.contract');

        $this->assertStringContainsString('- [ ] Tagged module', $this->app->make(ContractGenerator::class)->markdown());
    }
}
