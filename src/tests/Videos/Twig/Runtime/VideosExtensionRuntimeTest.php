<?php

namespace App\Tests\Videos\Twig\Runtime;

use App\Videos\Twig\Runtime\VideosExtensionRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VideosExtensionRuntimeTest extends TestCase
{
    private VideosExtensionRuntime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new VideosExtensionRuntime();
    }

    #[DataProvider('slugifyProvider')]
    public function testSlugify(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->runtime->slugify($input));
    }

    public static function slugifyProvider(): array
    {
        return [
            'basic string' => ['Hello World', 'hello-world'],
            'extra spaces' => ['  Hello    World  ', 'hello-world'],
            'special chars' => ['Hello @World!', 'hello-world'],
            'numbers'      => ['Video 123', 'video-123'],
            'lowercase'    => ['already-slugged', 'already-slugged'],
        ];
    }

    public function testRepeat(): void
    {
        $this->assertSame('abcabcabc', $this->runtime->repeat('abc', 3));
        $this->assertSame('', $this->runtime->repeat('abc', 0));
    }
}
