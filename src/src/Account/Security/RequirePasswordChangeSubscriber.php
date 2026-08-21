<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends a user flagged `mustChangePassword` to the change-password page and nowhere else
 * (FR-006). This cannot be expressed as an `access_control` rule, which matches on paths
 * rather than on user state.
 *
 * An adapter, not a workflow: it makes no business decision beyond reading the flag.
 */
final readonly class RequirePasswordChangeSubscriber implements EventSubscriberInterface
{
    /**
     * Routes that must stay reachable while the flag is set, or the application deadlocks:
     * the change-password page itself, and the way back out.
     */
    private const ALLOWED_ROUTES = [
        'account_password_change',
        'app_logout',
        'app_login',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 6 — below the firewall listener at 8, so a security token exists, and
        // below SessionIdleTimeoutSubscriber at 7, so an idle session is signed out rather
        // than redirected into the change-password page.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Asking for the user resolves the lazy firewall's token, which reads the session and
        // hits the database. Requests that arrive without a session cookie cannot be carrying
        // a flagged user, so the public pages are left alone.
        if (!$event->getRequest()->hasPreviousSession()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || !$user->mustChangePassword()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('account_password_change')));
    }
}
