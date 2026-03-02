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

    public function testGetPromotionPercentage()
    {
        $mathService = new MathService();

        $result = $mathService->getPromotionPercentage();
        $this->assertEquals(20, $result);
    }

    public function testCalculateDiscountedPrice()
    {
        $mathService = $this->getMockBuilder(MathService::class)
            ->onlyMethods(['getPromotionPercentage'])
            ->getMock();
        $mathService->expects($this->once())
            ->method('getPromotionPercentage')
            ->willReturn(50);


        $result = $mathService->calculateDiscountedPrice(50);
        $this->assertEquals(25, $result);

    }
}
