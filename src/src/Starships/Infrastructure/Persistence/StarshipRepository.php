<?php

namespace App\Starships\Infrastructure\Persistence;

use App\Starships\Domain\Entity\Starship;
use App\Starships\Domain\Enum\StarshipStatusEnum;
use App\Starships\Domain\Repository\StarshipRepositoryInterface;
use Psr\Log\LoggerInterface;

class StarshipRepository implements StarshipRepositoryInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function findAll(): array
    {
        $this->logger->info('Finding Starships');

        return [
            new Starship(
                1,
                'USS',
                'Garden',
                'Jon Doen',
                StarshipStatusEnum::WAITING,
                new \DateTimeImmutable('-2 hours'),
            ),
        ];
    }

    public function findById(int $id): ?Starship
    {
        foreach ($this->findAll() as $starship) {
            if ($starship->getId() === $id) {
                return $starship;
            }
        }

        return null;
    }
}
