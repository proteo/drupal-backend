<?php

namespace Proteo\DrupalBackend\Tests\View;

use Proteo\DrupalBackend\Tests\TestCase;

class HighlightComponentTest extends TestCase
{
    public function test_it_renders_highlighted_text_without_double_escaping(): void
    {
        $view = $this->blade(
            '<x-drupal::highlight :text="$text" :terms="$terms" />',
            ['text' => 'Tom & <script>x</script>', 'terms' => ['tom']],
        );

        $view->assertSee('<mark>Tom</mark> &amp; &lt;script&gt;x&lt;/script&gt;', false);
        $view->assertDontSee('&amp;amp;', false);
        $view->assertDontSee('<script>', false);
    }

    public function test_it_renders_escaped_text_without_terms(): void
    {
        $this->blade('<x-drupal::highlight text="a &amp; b" />')
            ->assertSee('a &amp;amp; b', false);
    }
}
