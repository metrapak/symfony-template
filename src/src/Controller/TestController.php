<?php

namespace App\Controller;

use App\Service\MathService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestController extends AbstractController
{
    #[Route('/test', name: 'app_test')]
    public function index(EntityManagerInterface $entityManager): JsonResponse
    {
        $entityManager->getConnection()->connect();

        return $this->json([
            'message' => 'Welcome to your new controller!',
            'path' => 'src/Controller/TestController.php',
            'dbConnected' => $entityManager->getConnection()->isConnected(),
        ]);
    }

    #[Route('/math', name: 'math_test')]
    public function math(MathService $math): Response
    {
        $sum = $math->add(2, 3);

        return new Response("2 + 3 = $sum");
    }
}
