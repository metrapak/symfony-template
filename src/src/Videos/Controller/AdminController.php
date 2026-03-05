<?php

namespace App\Videos\Controller;

use App\Videos\Entity\Category;
use App\Videos\Utils\CategoryTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/videos/admin', name: 'videos_admin_')]
class AdminController extends AbstractController
{
    #[Route('', name: 'my_profile')]
    public function index(): Response
    {
        return $this->render('videos/admin/my_profile.html.twig');

    }

    #[Route('/categories', name: 'categories')]
    public function categories(CategoryTree $categoryTree): Response
    {
        return $this->render('videos/admin/categories.html.twig', [
            'categories' => $categoryTree->buildTree(),
        ]);

    }

    #[Route('/edit-category/{id}', name: 'edit_category')]
    public function editCategory(Category $category): Response
    {
        return $this->render('videos/admin/edit_category.html.twig', [
            'category' => $category,
        ]);
    }

    #[Route('/delete-category/{id}', name: 'delete_category')]
    public function deleteCategory(Category $category, EntityManagerInterface $entityManager): Response
    {
        $entityManager->remove($category);
        $entityManager->flush();

        return $this->redirectToRoute('videos_admin_categories');

    }

    #[Route('/videos', name: 'videos')]
    public function videos(): Response
    {
        return $this->render('videos/admin/videos.html.twig');

    }

    #[Route('/upload-video', name: 'upload_video')]
    public function uploadVideo(): Response
    {
        return $this->render('videos/admin/upload_video.html.twig');

    }

    #[Route('/users', name: 'users')]
    public function users(): Response
    {
        return $this->render('videos/admin/users.html.twig');

    }

    public function getCategoriesOptions(CategoryTree $categoryTree, Category $editedCategory = null): Response
    {
        return $this->render('videos/admin/includes/_categories_options.html.twig', [
            'categories' => $categoryTree->buildTree(),
            'editedCategory' => $editedCategory,
        ]);

    }
}
