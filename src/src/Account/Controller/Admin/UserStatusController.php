<?php

declare(strict_types=1);

namespace App\Account\Controller\Admin;

use App\Account\Dto\DeleteUserInput;
use App\Account\Entity\User;
use App\Account\Exception\AccountException;
use App\Account\Form\DeleteUserFormType;
use App\Account\Service\UserAnonymizer;
use App\Account\Service\UserDeactivator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The two-tier removal model (FR-024, FR-025).
 *
 * Every action is a POST carrying a CSRF token, including the ones a link could have
 * expressed. A deactivation reachable by GET is a deactivation any page on the internet can
 * trigger with an `<img>` tag, and the confirmation dialog in front of these is a courtesy to
 * the operator, not a security control.
 */
final class UserStatusController extends AbstractController
{
    #[Route('/admin/users/{id}/deactivate', name: 'admin_users_deactivate', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function deactivate(
        Request $request,
        #[MapEntity(id: 'id')] User $user,
        #[CurrentUser] User $actor,
        UserDeactivator $deactivator,
    ): Response {
        $this->assertCsrf($request);

        try {
            $deactivator->deactivate($user, $actor);
        } catch (AccountException $e) {
            return $this->backToDirectory('error', $e->getMessage());
        }

        return $this->backToDirectory('success', \sprintf(
            '%s has been deactivated. They can no longer sign in; all of their history is preserved.',
            $user->getDisplayName(),
        ));
    }

    #[Route('/admin/users/{id}/reactivate', name: 'admin_users_reactivate', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function reactivate(
        Request $request,
        #[MapEntity(id: 'id')] User $user,
        #[CurrentUser] User $actor,
        UserDeactivator $deactivator,
    ): Response {
        $this->assertCsrf($request);

        try {
            $deactivator->reactivate($user, $actor);
        } catch (AccountException $e) {
            return $this->backToDirectory('error', $e->getMessage());
        }

        return $this->backToDirectory('success', \sprintf('%s can sign in again.', $user->getDisplayName()));
    }

    /**
     * GET renders the warning and the required reason; POST performs the erasure.
     *
     * The reason is a validated field rather than a hidden input, so this is a form page and
     * not a one-click action — which is the right amount of friction in front of something
     * FR-025 describes as irreversible.
     */
    #[Route('/admin/users/{id}/delete', name: 'admin_users_delete', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function delete(
        Request $request,
        #[MapEntity(id: 'id')] User $user,
        #[CurrentUser] User $actor,
        UserAnonymizer $anonymizer,
    ): Response {
        $displayName = $user->getDisplayName();

        $form = $this->createForm(DeleteUserFormType::class, new DeleteUserInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var DeleteUserInput $input */
            $input = $form->getData();

            try {
                $anonymizer->anonymize($user, $actor, (string) $input->reason);
            } catch (AccountException $e) {
                return $this->backToDirectory('error', $e->getMessage());
            }

            return $this->backToDirectory('success', \sprintf(
                'The personal information for %s has been removed. Their historical records now show "Deleted User".',
                $displayName,
            ));
        }

        return $this->render('admin/users/delete.html.twig', [
            'form' => $form,
            'user' => $user,
            // Passed rather than written into the template, so the warning always names the
            // value the anonymizer actually writes.
            'anonymous_name' => UserAnonymizer::ANONYMOUS_NAME,
        ]);
    }

    /**
     * The forms behind these routes are hand-written rather than Symfony Form objects — they
     * are a button and a token — so the token is checked here. `submit` is the stateless id
     * configured in `config/packages/csrf.yaml`; using any other id would silently fail to
     * validate.
     */
    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function backToDirectory(string $flashType, string $message): Response
    {
        $this->addFlash($flashType, $message);

        return $this->redirectToRoute('admin_users_index');
    }
}
