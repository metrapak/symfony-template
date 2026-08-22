<?php

declare(strict_types=1);

namespace App\Approval\Controller;

use App\Account\Entity\User;
use App\Approval\Service\NotificationInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The in-app notification inbox (FR-093, G-33).
 *
 * **There is no id in either route, and that is the authorization.** Both actions read the
 * current user from the token and work on their own rows; there is no subject to get wrong, so
 * there is no voter and no IDOR. A notification belongs to exactly one recipient and nobody
 * addresses somebody else's.
 *
 * Marking read is a POST with a CSRF token rather than something the list does on render —
 * see `NotificationInbox` for why a GET must not clear the indicator a parent depends on.
 */
final class NotificationController extends AbstractController
{
    #[Route('/notifications', name: 'notifications_index', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $viewer,
        NotificationInbox $inbox,
    ): Response {
        return $this->render('player/notifications.html.twig', [
            'notifications' => $inbox->recentFor($viewer),
            'unread' => $inbox->unreadCountFor($viewer),
        ]);
    }

    #[Route('/notifications/read', name: 'notifications_mark_read', methods: ['POST'])]
    public function markRead(
        Request $request,
        #[CurrentUser] User $viewer,
        NotificationInbox $inbox,
    ): Response {
        // NFR-X03, with the `submit` token id every other hand-rolled form in this codebase uses —
        // it is the one configured as stateless (`config/packages/csrf.yaml`), and a second id
        // here would quietly opt this form out of that. Without it a third-party page could clear
        // somebody's unread badge for them.
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $marked = $inbox->markAllRead($viewer);

        $this->addFlash('success', 0 === $marked
            ? 'Nothing was unread.'
            : \sprintf('Marked %d %s as read.', $marked, 1 === $marked ? 'notification' : 'notifications'));

        return $this->redirectToRoute('notifications_index');
    }
}
