<?php

namespace App\Videos\Controller;

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

    #[Route('/videos-list', name: 'videos_list')]
    public function list(): Response
    {
        return $this->render('videos/front/video_list.html.twig');
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
}
