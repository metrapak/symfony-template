<?php

namespace App\Videos\Controller;

use App\Videos\Entity\Category;
use App\Videos\Form\CategoryType;
use App\Videos\Utils\CategoryTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

    #[Route('/categories', name: 'categories', methods: ['GET', 'POST'])]
    public function categories(CategoryTree $categoryTree, Request $request, EntityManagerInterface $entityManager): Response
    {
        $category = new Category();

        $form = $this->createForm(CategoryType::class, $category);
        $is_invalid = null;

        if ($this->saveCategory($request, $entityManager, $category, $form)) {
            return $this->redirectToRoute('videos_admin_categories');
        } elseif ($request->isMethod('POST')) {
            $is_invalid = ' is-invalid';
        }

        return $this->render('videos/admin/categories.html.twig', [
            'categories' => $categoryTree->buildTree(),
            'form' => $form->createView(),
            'is_invalid' => $is_invalid,
        ]);

    }

    #[Route('/edit-category/{id}', name: 'edit_category', methods: ['GET', 'POST'])]
    public function editCategory(Category $category, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $is_invalid = null;

        if ($this->saveCategory($request, $entityManager, $category, $form)) {
            return $this->redirectToRoute('videos_admin_categories');
        } elseif ($request->isMethod('POST')) {
            $is_invalid = ' is-invalid';
        }

        return $this->render('videos/admin/edit_category.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
            'is_invalid' => $is_invalid,
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

    public function getCategoriesOptions(CategoryTree $categoryTree, ?Category $editedCategory = null): Response
    {
        return $this->render('videos/admin/includes/_categories_options.html.twig', [
            'categories' => $categoryTree->buildTree(),
            'editedCategory' => $editedCategory,
        ]);

    }

    private function saveCategory(Request $request, EntityManagerInterface $entityManager, Category $category, $form): bool
    {
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $categoryData = $request->request->all('category');

            $name = $categoryData['name'] ?? null;
            $category->setName($name);

            $parentId = $categoryData['parent'] ?? null;
            $parent = $entityManager->getRepository(Category::class)->find($parentId);
            $category->setParent($parent ?? null);

            $entityManager->persist($category);
            $entityManager->flush();

            return true;
        }

        return false;
    }
}
