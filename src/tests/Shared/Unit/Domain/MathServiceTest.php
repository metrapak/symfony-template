<?php

namespace App\Tests\Shared\Unit\Domain;

use App\Shared\Domain\Service\MathService;
use PHPUnit\Framework\TestCase;

class MathServiceTest extends TestCase
{
    public function testSomething(): void
    {
        $mathService = new MathService();
        $this->assertEquals(5, $mathService->add(2, 3));
    }
}
