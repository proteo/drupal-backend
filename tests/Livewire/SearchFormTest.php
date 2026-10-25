<?php

namespace Proteo\DrupalBackend\Tests\Livewire;

use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Livewire\Forms\SearchForm;
use Proteo\DrupalBackend\Search\TermSanitiser;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class SearchFormTest extends DescriptorTestCase
{
    public function test_the_too_short_message_follows_the_configured_minimum(): void
    {
        $config = $this->fixture('conference');
        $config['search']['term']['min'] = 4;
        $descriptor = $this->descriptor($config);

        $this->app->instance(Descriptor::class, $descriptor);
        $this->app->instance(TermSanitiser::class, new TermSanitiser($descriptor->search));

        $this->assertSame('Enter at least 4 letters or numbers.', SearchForm::tooShortMessage());
    }
}
