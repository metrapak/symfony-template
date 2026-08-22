<?php

declare(strict_types=1);

namespace App\Account\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ends an authenticated session that has gone unused for longer than the configured window
 * (FR-002, "sessions expire after a configured inactivity period").
 *
 * PHP's own settings cannot deliver that on their own, which is why this exists:
 * `session.gc_maxlifetime` only makes a session *eligible* for collection, and with the
 * native file handler collection is probabilistic (and on most distributions handed to a
 * system cron), so an abandoned session stays resumable for an unpredictable length of time.
 * `cookie_lifetime` is an absolute cap counted from when the cookie was issued, not an idle
 * window. Both remain configured as backstops; the window users actually experience is the
 * one enforced here, in application code, on every request and regardless of handler.
 */
final readonly class SessionIdleTimeoutSubscriber implements EventSubscriberInterface
{
    /**
     * Kept distinct from any `_security_*` key so Symfony's own session handling never
     * collides with it.
     */
    private const LAST_ACTIVITY_KEY = 'account_last_activity_at';

    public const EXPIRED_MESSAGE = 'You were signed out because you were inactive. Please sign in again.';

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private int $sessionIdleTtl,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 7 — below the firewall listener at 8 so a token exists, and above
        // RequirePasswordChangeSubscriber at 6 so expiry wins over its redirect.
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // No session cookie means there is no session to expire, and touching the session
        // here would start one for every anonymous visitor.
        if (!$request->hasPreviousSession()) {
            return;
        }

        if (null === $this->security->getUser()) {
            return;
        }

        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $lastActivity = $session->get(self::LAST_ACTIVITY_KEY);

        if (\is_int($lastActivity) && $now - $lastActivity > $this->sessionIdleTtl) {
            // Not a redirect to the login page while still authenticated: the session is
            // actually invalidated, so the cookie left in an abandoned browser is spent.
            $this->security->logout(false);

            // Set after the logout, which invalidates the session, so the message lands in
            // the fresh one. Explaining why they were signed out is a courtesy, so a session
            // implementation without flashes simply skips it.
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', self::EXPIRED_MESSAGE);
            }

            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));

            return;
        }

        $session->set(self::LAST_ACTIVITY_KEY, $now);
    }
}
