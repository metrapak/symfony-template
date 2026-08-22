<?php

declare(strict_types=1);

namespace App\Account\Controller\Admin;

use App\Account\Entity\User;
use App\Account\Security\ImpersonateVoter;
use App\Account\Service\ImpersonationContext;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Firewall\SwitchUserListener;

/**
 * Starting and leaving an impersonation (FR-028, FR-029).
 *
 * Neither action performs the switch. Symfony's `SwitchUserListener` does that when it sees
 * `_switch_user` on a request, so both of these produce a redirect carrying that parameter
 * and let the firewall do the work — which also means the audit subscriber and the FR-030
 * block apply, and cannot be routed around by skipping this controller.
 */
final class ImpersonationController extends AbstractController
{
    #[Route('/admin/users/{id}/impersonate', name: 'admin_users_impersonate', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function start(
        Request $request,
        #[MapEntity(id: 'id')] User $user,
        UrlGeneratorInterface $urlGenerator,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Denies here for the same reasons the subscriber denies later; this one turns it
        // into a clean 403 on a POST the operator actually made, instead of a failure part
        // way through a redirect chain.
        $this->denyAccessUnlessGranted(ImpersonateVoter::IMPERSONATE, $user);

        // `/dashboard` rather than a role-specific route: it is the existing hub that sends
        // each role to its own landing page, so this controller does not need to know which
        // dashboard the target has.
        return $this->redirect($urlGenerator->generate('account_dashboard') . '?_switch_user=' . rawurlencode($user->getUserIdentifier()));
    }

    /**
     * Reachable while the operator holds the *target's* roles, which is why it lives outside
     * `/admin` — a rule requiring ROLE_SUPER_ADMIN would deny the exit to the very session
     * that needs it.
     */
    #[Route('/impersonation/exit', name: 'admin_impersonation_exit', methods: ['GET'])]
    public function exit(UrlGeneratorInterface $urlGenerator, ImpersonationContext $impersonationContext): RedirectResponse
    {
        // Without this guard, a stray click on a stale banner reaches SwitchUserListener with
        // no switch to exit; it raises an authentication error, and the firewall answers by
        // bouncing a perfectly valid session to the login page.
        if (!$impersonationContext->isImpersonating()) {
            return $this->redirectToRoute('account_dashboard');
        }

        // Aimed at /admin so that the request which restores the original token is one the
        // restored Super Admin is allowed to make; the firewall answers it before
        // access_control is reached, and the follow-up lands on the admin dashboard.
        return $this->redirect(
            $urlGenerator->generate('admin_dashboard') . '?_switch_user=' . SwitchUserListener::EXIT_VALUE,
        );
    }
}
