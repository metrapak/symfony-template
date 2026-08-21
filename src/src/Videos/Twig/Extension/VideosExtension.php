<?php

namespace App\Videos\Twig\Extension;

use App\Videos\Twig\Runtime\VideosExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class VideosExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('slugify', [VideosExtensionRuntime::class, 'slugify']),
            new TwigFilter('repeat', [VideosExtensionRuntime::class, 'repeat']),
        ];
    }
}
