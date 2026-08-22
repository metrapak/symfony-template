<?php

declare(strict_types=1);

namespace App\Membership\Controller\Trainer;

use App\Account\Entity\User;
use App\Account\Repository\OrganizationRepository;
use App\Account\Service\TenantContext;
use App\Membership\Entity\ShareLink;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\Security\ShareLinkVoter;
use App\Membership\Service\ShareLinkGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Player ShareLink management (FR-040).
 *
 * `/trainer/*` is already `ROLE_TRAINER` through `access_control`, and that is exactly the
 * check that does not protect anything with an id in the URL: every trainer holds the role.
 * The object-level rule is `ShareLinkVoter`, applied through `#[IsGranted]` on the actions
 * that take a link.
 */
final class ShareLinkController extends AbstractController
{
    #[Route('/trainer/share-links', name: 'trainer_share_links_index', methods: ['GET'])]
    public function index(TenantContext $tenant, ShareLinkRepository $links): Response
    {
        $organizationId = $tenant->requireOrganizationId();

        return $this->render('trainer/share_links/index.html.twig', [
            'links' => $links->findPlayerLinksFor($organizationId),
        ]);
    }

    #[Route('/trainer/share-links', name: 'trainer_share_links_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $trainer,
        TenantContext $tenant,
        OrganizationRepository $organizations,
        ShareLinkGenerator $generator,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->assertCsrf($request);

        $organization = $organizations->find($tenant->requireOrganizationId());

        if (null === $organization) {
            throw $this->createNotFoundException('No organization in context.');
        }

        $generator->createPlayerLink($organization, $trainer);
        $entityManager->flush();

        $this->addFlash('success', 'A new player link is ready to share.');

        return $this->redirectToRoute('trainer_share_links_index');
    }

    #[Route('/trainer/share-links/{id}/deactivate', name: 'trainer_share_links_deactivate', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(ShareLinkVoter::MANAGE, subject: 'link')]
    public function deactivate(
        Request $request,
        #[MapEntity(id: 'id')] ShareLink $link,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): Response {
        $this->assertCsrf($request);

        $link->deactivate($clock->now());
        $entityManager->flush();

        // G-19: withdrawing an invitation does not expel the players who already accepted it.
        $this->addFlash('success', 'That link no longer works. Players who already joined through it keep their place.');

        return $this->redirectToRoute('trainer_share_links_index');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
