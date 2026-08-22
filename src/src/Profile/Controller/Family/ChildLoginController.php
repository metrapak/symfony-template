<?php

declare(strict_types=1);

namespace App\Profile\Controller\Family;

use App\Account\Entity\User;
use App\Profile\Dto\ChildLoginInput;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ChildLoginAlreadyExists;
use App\Profile\Exception\ProfileNotManaged;
use App\Profile\Exception\UsernameAlreadyTaken;
use App\Profile\Form\ChildLoginFormType;
use App\Profile\Security\ChildActionVoter;
use App\Profile\Security\ProfileVoter;
use App\Profile\Service\ChildLoginManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A parent giving their child a login, and taking it away again (FR-067, G-23).
 *
 * G-23 is the gap: US-01.06 says a child "can optionally have separate login" and no story says
 * how those credentials come into existence. This is the flow — the parent picks a username and
 * a first password, the address is derived and undeliverable, and the child must change the
 * password on first sign-in. All of that reasoning lives on `ChildLoginManager`; this controller
 * only turns it into three buttons and a form.
 *
 * A child may not reach any of it. `MANAGE_CHILDREN` refuses them (FR-068), which matters more
 * here than almost anywhere else in the section: a child who could open this page could grant a
 * sibling a login, or reset the password of one.
 *
 * Revoking and restoring are POST-only and CSRF-checked, because both change who can sign in.
 * Neither deletes anything — a revoked login is a deactivated account whose history survives
 * (FR-026), and the family page shows "login switched off" as a state distinct from "no login".
 */
#[IsGranted(ChildActionVoter::MANAGE_CHILDREN)]
final class ChildLoginController extends AbstractController
{
    #[Route(
        '/family/children/{id}/login',
        name: 'family_child_login_new',
        methods: ['GET', 'POST'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::EDIT, subject: 'child')]
    public function create(
        Request $request,
        #[CurrentUser] User $parent,
        #[MapEntity(id: 'id')] PlayerProfile $child,
        ChildLoginManager $logins,
    ): Response {
        // A profile with a login already has one to manage, not one to create. Redirecting rather
        // than 404ing: the parent asked a reasonable question and the family page is the answer.
        if ($child->hasOwnLogin()) {
            $this->addFlash('warning', \sprintf('%s already has a login.', $child->getDisplayName()));

            return $this->redirectToRoute('family_index');
        }

        $form = $this->createForm(ChildLoginFormType::class, new ChildLoginInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ChildLoginInput $input */
            $input = $form->getData();

            try {
                $account = $logins->enable($parent, $child, $input);
            } catch (UsernameAlreadyTaken $e) {
                // Both the pre-check and the unique index land here. Reported on the field the
                // parent can change, which is the only actionable thing to say.
                $form->get('username')->addError(new FormError($e->getMessage()));

                return $this->renderForm($form, $child);
            } catch (ChildLoginAlreadyExists $e) {
                $this->addFlash('warning', $e->getMessage());

                return $this->redirectToRoute('family_index');
            } catch (ProfileNotManaged) {
                throw $this->createAccessDeniedException('That profile is not yours to manage.');
            }

            // The username, not the derived address: the address is an implementation detail the
            // child must never be asked to type, and showing it would invite them to try.
            $this->addFlash('success', \sprintf(
                '%s can now sign in as "%s". They will be asked to choose their own password the first time.',
                $child->getDisplayName(),
                (string) $account->getLoginUsername(),
            ));

            return $this->redirectToRoute('family_index');
        }

        return $this->renderForm($form, $child);
    }

    #[Route(
        '/family/children/{id}/login/revoke',
        name: 'family_child_login_revoke',
        methods: ['POST'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::EDIT, subject: 'child')]
    public function revoke(
        Request $request,
        #[CurrentUser] User $parent,
        #[MapEntity(id: 'id')] PlayerProfile $child,
        ChildLoginManager $logins,
    ): Response {
        $this->assertCsrf($request);

        try {
            $logins->revoke($parent, $child);
        } catch (ProfileNotManaged) {
            throw $this->createAccessDeniedException('That profile is not yours to manage.');
        }

        $this->addFlash('success', \sprintf(
            '%s can no longer sign in. Their profile, trainers and history are unchanged.',
            $child->getDisplayName(),
        ));

        return $this->redirectToRoute('family_index');
    }

    #[Route(
        '/family/children/{id}/login/restore',
        name: 'family_child_login_restore',
        methods: ['POST'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    #[IsGranted(ProfileVoter::EDIT, subject: 'child')]
    public function restore(
        Request $request,
        #[CurrentUser] User $parent,
        #[MapEntity(id: 'id')] PlayerProfile $child,
        ChildLoginManager $logins,
    ): Response {
        $this->assertCsrf($request);

        try {
            $logins->restore($parent, $child);
        } catch (ProfileNotManaged) {
            throw $this->createAccessDeniedException('That profile is not yours to manage.');
        }

        $this->addFlash('success', \sprintf('%s can sign in again.', $child->getDisplayName()));

        return $this->redirectToRoute('family_index');
    }

    private function renderForm(FormInterface $form, PlayerProfile $child): Response
    {
        return $this->render('family/child_login.html.twig', [
            'form' => $form,
            'child' => $child,
        ]);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
