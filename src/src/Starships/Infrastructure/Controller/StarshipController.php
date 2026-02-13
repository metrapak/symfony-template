<?php

namespace App\Starships\Infrastructure\Controller;

use App\Starships\Infrastructure\Persistence\StarshipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StarshipController extends AbstractController
{
    #[Route('/starships/{id<\d+>}', name: 'app_starship_show', methods: ['GET'])]
    public function show(int $id, StarshipRepository $repository): Response
    {
        $starship = $repository->findById($id);

        if (!$starship) {
            throw $this->createNotFoundException('Starship not found');
        }

        return $this->render('starship/show.html.twig', [
            'ship' => $starship,
        ]);
    }
}
