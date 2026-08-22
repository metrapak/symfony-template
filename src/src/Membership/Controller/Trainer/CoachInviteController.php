<?php

declare(strict_types=1);

namespace App\Membership\Controller\Trainer;

use App\Account\Entity\User;
use App\Account\Repository\OrganizationRepository;
use App\Account\Service\TenantContext;
use App\Membership\Dto\CoachInviteInput;
use App\Membership\Entity\ShareLink;
use App\Membership\Exception\CoachAlreadyAssignedElsewhere;
use App\Membership\Form\CoachInviteFormType;
use App\Membership\Security\ShareLinkVoter;
use App\Membership\Service\CoachDirectory;
use App\Membership\Service\CoachInvitationService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Coach invitations and the Coaches list (FR-041, FR-046, US-01.08).
 */
final class CoachInviteController extends AbstractController
{
    #[Route('/trainer/coaches', name: 'trainer_coaches_index', methods: ['GET'])]
    public function index(TenantContext $tenant, CoachDirectory $directory): Response
    {
        return $this->render('trainer/coaches/index.html.twig', [
            'invitations' => $directory->listFor($tenant->requireOrganizationId()),
        ]);
    }

    #[Route('/trainer/coaches/invite', name: 'trainer_coaches_invite', methods: ['GET', 'POST'])]
    public function invite(
        Request $request,
        #[CurrentUser] User $trainer,
        TenantContext $tenant,
        OrganizationRepository $organizations,
        CoachInvitationService $invitations,
    ): Response {
        $organization = $organizations->find($tenant->requireOrganizationId());

        if (null === $organization) {
            throw $this->createNotFoundException('No organization in context.');
        }

        $form = $this->createForm(CoachInviteFormType::class, new CoachInviteInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CoachInviteInput $input */
            $input = $form->getData();

            try {
                $result = $invitations->invite($organization, $trainer, $input);
            } catch (CoachAlreadyAssignedElsewhere $e) {
                // BR-044, reported on the address that caused it. The message deliberately
                // does not name the other organization.
                $form->get('email')->addError(new FormError($e->getMessage()));

                return $this->render('trainer/coaches/invite.html.twig', ['form' => $form]);
            }

            $this->addFlash(
                $result->invitationSent ? 'success' : 'warning',
                $result->invitationSent
                    ? \sprintf('Invitation sent to %s. It is single-use and expires in 7 days.', $result->link->getTargetEmail())
                    : \sprintf('The invitation for %s was created, but the email could not be sent. Use "Resend" to try again.', $result->link->getTargetEmail()),
            );

            return $this->redirectToRoute('trainer_coaches_index');
        }

        return $this->render('trainer/coaches/invite.html.twig', ['form' => $form]);
    }

    /**
     * FR-046 — a fresh code and a fresh seven-day window for an invitation that lapsed.
     */
    #[Route('/trainer/coaches/invite/{id}/resend', name: 'trainer_coaches_invite_resend', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(ShareLinkVoter::MANAGE, subject: 'link')]
    public function resend(
        Request $request,
        #[MapEntity(id: 'id')] ShareLink $link,
        #[CurrentUser] User $trainer,
        CoachInvitationService $invitations,
    ): Response {
        $this->assertCsrf($request);

        $result = $invitations->resend($link, $trainer);

        $this->addFlash(
            $result->invitationSent ? 'success' : 'warning',
            $result->invitationSent
                ? \sprintf('A new invitation is on its way to %s. The previous link no longer works.', $link->getTargetEmail())
                : 'The invitation was renewed, but the email could not be sent. Check the mail transport and try again.',
        );

        return $this->redirectToRoute('trainer_coaches_index');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
