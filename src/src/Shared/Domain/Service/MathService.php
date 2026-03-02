<?php

namespace App\Shared\Domain\Service;

class MathService
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function calculateDiscountedPrice(float $originalPrice): float
    {
        $discountPercentage = $this->getPromotionPercentage();

        $discountAmount = ($originalPrice * $discountPercentage) / 100;

        return round($originalPrice - $discountAmount, 2);
    }

    public function getPromotionPercentage(): int
    {
        return 20;
    }
}
