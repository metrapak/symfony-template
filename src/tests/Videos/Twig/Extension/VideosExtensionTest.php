<?php

namespace App\Tests\Videos\Twig\Extension;

use App\Videos\Twig\Extension\VideosExtension;
use App\Videos\Twig\Runtime\VideosExtensionRuntime;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

class VideosExtensionTest extends TestCase
{
    public function testGetFiltersRegistersCorrectFilters(): void
    {
        $extension = new VideosExtension();
        $filters = $extension->getFilters();

        $this->assertCount(2, $filters);
        $this->assertInstanceOf(TwigFilter::class, $filters[0]);
        $this->assertInstanceOf(TwigFilter::class, $filters[1]);

        $this->assertSame('slugify', $filters[0]->getName());
        $this->assertSame([VideosExtensionRuntime::class, 'slugify'], $filters[0]->getCallable());

        $this->assertSame('repeat', $filters[1]->getName());
        $this->assertSame([VideosExtensionRuntime::class, 'repeat'], $filters[1]->getCallable());
    }
}
