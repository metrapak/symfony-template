<?php

namespace App\Products\Domain\Repository;

use App\Products\Domain\Entity\Product;
use Doctrine\ORM\Tools\Pagination\Paginator;

interface ProductRepositoryInterface
{
    public function findAllWithCategoryPaginated(int $page, int $limit): Paginator;

    /**
     * @return Product[]
     */
    public function findAllGreaterThanPrice(int $price): array;

    /**
     * @return Product[]
     */
    public function findAllGreaterThanPrice2(int $price): array;

    public function findAllGreaterThanPrice3(int $price): array;
}
