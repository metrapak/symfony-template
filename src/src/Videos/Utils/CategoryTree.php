<?php

namespace App\Videos\Utils;

use App\Videos\Twig\Runtime\VideosExtensionRuntime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CategoryTree
{
    public array $categories;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly VideosExtensionRuntime $slugger,
    ) {
        $this->categories = $this->getCategories();
    }

    public function buildTree(?int $parentId = null): array
    {
        $tree = [];
        foreach ($this->categories as $category) {
            if ($category['parent_id'] === $parentId) {
                $children = $this->buildTree($category['id']);

                if ($children) {
                    $category['children'] = $children;
                }

                $slug = $this->slugger->slugify($category['name']);

                $category['url'] = $this->urlGenerator->generate('videos_list', [
                    'id' => $category['id'],
                    'name' => $slug,
                ]);

                $tree[] = $category;
            }
        }

        return $tree;
    }

    public function getMainParent(int $id): array
    {
        $index = array_search($id, array_column($this->categories, 'id'));

        if (false === $index) {
            return [];
        }

        $category = $this->categories[$index];

        if (null === $category['parent_id']) {
            $slug = $this->slugger->slugify($category['name']);
            $category['url'] = $this->urlGenerator->generate('videos_list', [
                'id' => $category['id'],
                'name' => $slug,
            ]);

            return $category;
        }

        return $this->getMainParent((int) $category['parent_id']);
    }

    protected function getCategories(): array
    {
        return $this->entityManager->getConnection()
            ->executeQuery('SELECT * FROM categories ORDER BY name ASC')
            ->fetchAllAssociative();
    }

    public function getChildIds(int $parentId): array
    {
        $ids = [];
        foreach ($this->categories as $category) {
            if ($category['parent_id'] === $parentId) {
                $ids[] = $category['id'];
                $ids = array_merge($ids, $this->getChildIds($category['id']));
            }
        }

        return $ids;
    }
}
