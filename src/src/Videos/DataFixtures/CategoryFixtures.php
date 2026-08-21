<?php

namespace App\Videos\DataFixtures;

use App\Videos\Entity\Category;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CategoryFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $this->loadCategories($manager, $this->getCategoriesData());
    }

    private function loadCategories(ObjectManager $manager, array $data, ?Category $parent = null): void
    {
        foreach ($data as $name => $children) {
            $category = new Category();
            $category->setName($name);
            $category->setParent($parent);

            $manager->persist($category);

            if (!empty($children)) {
                $this->loadCategories($manager, $children, $category);
            }
        }

        $manager->flush();
    }

    private function getCategoriesData(): array
    {
        return [
            'Electronics' => [
                'Cameras' => [],
                'Computers' => [
                    'Laptops' => [
                        'Apple' => [],
                        'Asus' => [],
                        'Dell' => [],
                        'Lenovo' => [],
                        'HP' => [],
                    ],
                    'Desktops' => [],
                ],
                'Cell phones' => [],
            ],
            'Toys' => [],
            'Books' => [
                'Children\'s Books' => [],
                'Kindle eBooks' => [],
            ],
            'Movies' => [
                'Family' => [],
                'Romance' => [
                    'Romantic Comedy' => [],
                    'Romantic Drama' => [],
                ],
            ],
        ];
    }
}
