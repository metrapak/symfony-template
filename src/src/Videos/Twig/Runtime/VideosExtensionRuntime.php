<?php

namespace App\Videos\Twig\Runtime;

use Twig\Extension\RuntimeExtensionInterface;

class VideosExtensionRuntime implements RuntimeExtensionInterface
{
    public function __construct()
    {
        // Inject dependencies if needed
    }

    public function slugify($string): array|false|string|null
    {
        $string = preg_replace('/ +/', '-', trim($string));
        $string = preg_replace('/[^A-Za-z0-9\-]+/', '', $string);

        return mb_strtolower($string, 'UTF-8');
    }

    public function repeat(string $string, int $times): string
    {
        return str_repeat($string, $times);
    }
}
