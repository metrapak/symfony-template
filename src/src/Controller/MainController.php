<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MainController extends AbstractController
{
    #[Route('/', name: 'homepage')]
    public function homepage(): Response
    {
        $myShip = [
            'name' => 'USS',
            'class' => 'Garden',
            'captain' => 'Jon Doen',
            'status' => 'under construction',
        ];

        return $this->render('main/homepage.html.twig', [
            'myShip' => $myShip,
        ]);
    }
}
