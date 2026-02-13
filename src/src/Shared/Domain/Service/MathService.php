<?php

namespace App\Shared\Domain\Service;

class MathService
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
