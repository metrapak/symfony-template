<?php

namespace App\Controller;

use App\Repository\StarshipRepository;
use Symfony\Bridge\Twig\Command\DebugCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MainController extends AbstractController
{
    #[Route('/', name: 'homepage')]
    public function homepage(
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

        return $this->render('main/homepage.html.twig', [
            'ship' => $ship,
            'issData' => $issData,
        ]);
    }

}
