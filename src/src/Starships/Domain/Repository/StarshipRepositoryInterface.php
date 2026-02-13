<?php

namespace App\Starships\Domain\Repository;

use App\Starships\Domain\Entity\Starship;

interface StarshipRepositoryInterface
{
    /**
     * @return Starship[]
     */
    public function findAll(): array;

    public function findById(int $id): ?Starship;
}
