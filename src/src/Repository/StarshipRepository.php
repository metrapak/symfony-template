<?php

namespace App\Repository;

use App\Model\Starship;
use Psr\Log\LoggerInterface;

class StarshipRepository
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function findAll(): array
    {
        $this->logger->info("Finding Starships");
        return [
            new Starship(1, 'USS', 'Garden', 'Jon Doen', 'under construction'),

        ];

    }

    public function findById(int $id): ?Starship
    {

        foreach ($this->findAll() as $starship) {
            if ($starship->getId() === $id) {
                return $starship;
            }
        }
        return NULL;
    }
}