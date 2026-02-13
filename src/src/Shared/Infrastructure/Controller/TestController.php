<?php

namespace App\Shared\Infrastructure\Controller;

use App\Shared\Domain\Service\MathService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    /**
     * This route has a greedy pattern and is defined first.
     */
    #[Route(
        '/blog/{slug}',
        name: 'blog_show',
        requirements: ['slug' => '.+'],
        defaults: ['slug' => 'test'],
        methods: ['GET'],
    )]
    public function show(Request $request, string $slug): Response
    {
        $routeName = $request->attributes->get('_route');
        $routeParameters = $request->attributes->get('_route_params');
        $allAttributes = $request->attributes->all();

        return $this->json([
            'Showing blog post: ' . $slug,
        ]);
    }

    /**
     * This route could not be matched without defining a higher priority than 0.
     */
    #[Route(
        path: [
            'en' => '/blog/list',
            'nl' => '/blog/list-nl',
            '_default' => '/blog/list',
        ],
        name: 'blog_list',
        priority: 2,
    )]
    public function list(): Response
    {
        $this->generateUrl('blog_show');

        return $this->json(['Showing blog list']);
    }
}
