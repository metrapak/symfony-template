<?php

namespace App\Shared\Infrastructure\Controller;

use App\Starships\Infrastructure\Persistence\StarshipRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MainController extends AbstractController
{
    public function __construct(private LoggerInterface $logger)
    {

        $this->logger->info('MainController instantiated');
    }

    /**
     * The public front door: sign in, and see every section the viewer may reach.
     *
     * Signed out it is the only page in the application that offers a way *in* other than the
     * bare `/login` form, so the sign-in card posts straight to the firewall's check path
     * (`app_login`) rather than linking to it — one fewer round trip before a visitor can type
     * a password. `_failure_path` sends a rejected attempt back here instead of to `/login`,
     * which is why this action reads `AuthenticationUtils`: the error belongs on the form the
     * visitor actually used. `HttpUtils` refuses a host other than this one, so that parameter
     * is not a redirect an attacker can steer off-site.
     *
     * Signed in it is a section index, not a second dashboard: every destination below is behind
     * the same `is_granted` check that guards its route (FR-010), so the page never advertises a
     * page that would answer 403.
     */
    #[Route('/', name: 'homepage', methods: ['GET'])]
    public function homepage(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('main/homepage.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/starships', name: 'starships')]
    public function starships(
        StarshipRepository $repository,
        CacheInterface $issLocationPool,
        HttpClientInterface $httpClient,
    ): Response {
        $starships = $repository->findAll();
        $ship = reset($starships);


        $issData = $issLocationPool->get('iss_location_data', function (ItemInterface $item) use ($httpClient) {
            $response = $httpClient->request('GET', 'https://api.wheretheiss.at/v1/satellites/25544');

            return $response->toArray();
        });

        $response = $this->render('starship/starships.html.twig', [
            'ship' => $ship,
            'issData' => $issData,
        ]);

        return $response;

    }

    #[Route('/available-routes', name: 'available_routes')]
    public function availableRoutes(): Response
    {
        return $this->render('main/routes.html.twig');
    }

    #[Route('/generate-url/{param?}', name: 'generate_url')]
    public function generateUrlCustom(): never
    {
        exit($this->generateUrl('generate_url', ['param' => 10]));
    }

    #[Route('/redirect-test', name: 'redirect_test')]
    public function redirectTest(): RedirectResponse
    {
        return $this->redirectToRoute('generate_url');
    }

    #[Route('/forward-test', name: 'forward_test')]
    public function forwardController(): Response
    {
        return $this->forward('App\Shared\Infrastructure\Controller\MainController::generateUrlCustom');
    }

    #[Route('/most-popular-posts', name: 'most_popular_posts')]
    public function mostPopularPosts(): Response
    {
        $posts = ['Post 1', 'Post 2', 'Post 3'];

        return $this->render('main/most-popular-posts.html.twig', [
            'posts' => $posts,
        ]);
    }
}
