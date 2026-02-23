<?php

namespace App\Shared\Infrastructure\Controller;

use App\Starships\Infrastructure\Persistence\StarshipRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Command\DebugCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MainController extends AbstractController
{
    public function __construct(private LoggerInterface $logger)
    {

        $this->logger->info('MainController instantiated');
        $this->logger->alert('alert from MainController constructor');

    }

    #[Route('/', name: 'homepage')]
    public function homepage(
        Request $request,
        SessionInterface $session,
        StarshipRepository $repository,
        HttpClientInterface $httpClient,
        CacheInterface $issLocationPool,
        int $issLocationCacheTtl,
        #[Autowire(service: 'twig.command.debug')]
        DebugCommand $twigdebugCommand,
    ): Response {
        $output = new BufferedOutput();
        $twigdebugCommand->run(new ArrayInput([]), $output);

        $starships = $repository->findAll();
        $ship = reset($starships);

        //        $issData = $issLocationPool->get('iss_location_data', function (ItemInterface $item) use ($httpClient) {
        //            $response = $httpClient->request('GET', 'https://api.wheretheiss.at/v1/satellites/25544');
        //
        //            return $response->toArray();
        //        });
        $issData = [];

        $this->addFlash('success', 'Welcome to the homepage! (added as a success message)');
        $this->addFlash('notice', 'Welcome to the homepage! (added as a notice message)');
        $response = $this->render('main/homepage.html.twig', [
            'ship' => $ship,
            'issData' => $issData,
        ]);

        $cookie = new Cookie('visited_homepage', 'visited_homepage', strtotime('+1 day'));
        $response->headers->setCookie($cookie);

        $request->cookies->get('visited_homepage');

        $session->set('visited_homepage', true);
        $session->remove('visited_homepage');
        if ($session->has('visited_homepage')) {
            exit($session->get('visited_homepage'));
        }

        $request->isXmlHttpRequest(); // Is it an AJAX request?

        return $response;
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
}
