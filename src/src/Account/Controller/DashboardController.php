<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Entity\User;
use App\Account\Service\RoleDashboardResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `/dashboard` is a redirect hub, not a template that branches four ways (FR-008): each
 * role then owns a stable URL that `access_control` can guard on its own.
 */
final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'account_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, RoleDashboardResolver $resolver): RedirectResponse
    {
        return $this->redirectToRoute($resolver->resolveRouteName($user->getRole()));
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function admin(): Response
    {
        return $this->renderDashboard('Super Admin');
    }

    #[Route('/trainer', name: 'trainer_dashboard', methods: ['GET'])]
    public function trainer(): Response
    {
        return $this->renderDashboard('Trainer');
    }

    #[Route('/coach', name: 'coach_dashboard', methods: ['GET'])]
    public function coach(): Response
    {
        return $this->renderDashboard('Coach');
    }

    #[Route('/family', name: 'family_dashboard', methods: ['GET'])]
    public function family(): Response
    {
        return $this->renderDashboard('Family');
    }

    private function renderDashboard(string $roleLabel): Response
    {
        return $this->render('account/dashboard.html.twig', ['roleLabel' => $roleLabel]);
    }
}
