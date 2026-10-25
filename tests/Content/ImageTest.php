<?php

namespace Proteo\DrupalBackend\Tests\Content;

use Proteo\DrupalBackend\Content\Image;
use Proteo\DrupalBackend\Tests\TestCase;

class ImageTest extends TestCase
{
    public function test_url_for_returns_the_style_when_offered(): void
    {
        $image = new Image('https://d.test/o.jpg', styles: ['medium' => 'https://d.test/m.jpg']);

        $this->assertSame('https://d.test/m.jpg', $image->urlFor('medium'));
        $this->assertTrue($image->hasStyle('medium'));
    }

    public function test_url_for_falls_back_to_the_original(): void
    {
        $image = new Image('https://d.test/o.jpg', styles: ['medium' => 'https://d.test/m.jpg', 'blank' => '']);

        $this->assertSame('https://d.test/o.jpg', $image->urlFor());
        $this->assertSame('https://d.test/o.jpg', $image->urlFor('large'));
        $this->assertSame('https://d.test/o.jpg', $image->urlFor('blank'));
        $this->assertFalse($image->hasStyle(null));
        $this->assertFalse($image->hasStyle(''));
    }
}
