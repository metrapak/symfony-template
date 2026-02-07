<?php

namespace App\Controller;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CategoryController extends AbstractController
{
    #[Route('/category/list', name: 'category_list')]
    public function list(CategoryRepository $repository): Response
    {
        $categories = $repository->findAll();

        return $this->json($categories);

    }

    #[Route('/category/delete/{id}', name: 'category_delete')]
    public function delete(EntityManagerInterface $entityManager, int $id): Response
    {
        $category = $entityManager->find(Category::class, $id);


        if (!$category) {
            return new Response('Category not found', Response::HTTP_NOT_FOUND);
        }

        $entityManager->remove($category);
        $entityManager->flush();

        return new Response('Category deleted successfully', Response::HTTP_OK);

    }

}