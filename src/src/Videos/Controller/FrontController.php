<?php

namespace App\Videos\Controller;

use App\Videos\Entity\Category;
use App\Videos\Utils\CategoryTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FrontController extends AbstractController
{
    #[Route('/videos', name: 'videos_front')]
    public function index(): Response
    {
        return $this->render('videos/front/index.html.twig');
    }

    #[Route('/videos-list/category/{name}/{id}', name: 'videos_list')]
    public function list(int $id, CategoryTree $categoryTree, EntityManagerInterface $entityManager): Response
    {
        $currentCategory = $entityManager->getRepository(Category::class)->find($id);
        $mainParent = $categoryTree->getMainParent($id);
        $subcategories = $categoryTree->buildTree($mainParent['id']);

        return $this->render('videos/front/video_list.html.twig', [
            'current_category' => $currentCategory,
            'main_category' => $mainParent,
            'subcategories' => $subcategories,
        ]);
    }

    #[Route('/video-details', name: 'video_details')]
    public function videoDetails(): Response
    {
        return $this->render('videos/front/video_details.html.twig');
    }

    #[Route('/videos/search-results', name: 'videos_search_results', methods: ['POST'])]
    public function searchResults(): Response
    {
        return $this->render('videos/front/search_results.html.twig');
    }

    #[Route('/videos/pricing', name: 'videos_pricing')]
    public function pricing(): Response
    {
        return $this->render('videos/front/pricing.html.twig');
    }

    #[Route('/videos/register', name: 'videos_register')]
    public function register(): Response
    {
        return $this->render('videos/front/register.html.twig');
    }

    #[Route('/videos/login', name: 'videos_login')]
    public function login(): Response
    {
        return $this->render('videos/front/login.html.twig');
    }

    #[Route('/videos/payment', name: 'videos_payment')]
    public function payment(): Response
    {
        return $this->render('videos/front/payment.html.twig');
    }

    public function mainCategories(EntityManagerInterface $entityManager): Response
    {
        $categories = $entityManager
            ->getRepository(Category::class)
            ->findBy(['parent' => null], ['name' => 'ASC']);

        return $this->render('videos/front/includes/_main_categories.html.twig', [
            'categories' => $categories,
        ]);
    }
}
