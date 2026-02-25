<?php

namespace App\Videos\Controller;

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
    public function categories(): Response
    {
        return $this->render('videos/admin/categories.html.twig');

    }

    #[Route('/edit-category', name: 'edit_category')]
    public function editCategory(): Response
    {
        return $this->render('videos/admin/edit_category.html.twig');

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
}
