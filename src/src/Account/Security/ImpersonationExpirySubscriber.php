<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Enum\ImpersonationEndReason;
use App\Account\Repository\UserRepository;
use App\Account\Service\ImpersonationAuditRecorder;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Ends an impersonation that has run past its time limit (FR-031, gap G-14).
 *
 * Symfony's `switch_user` has no native expiry, so this is the mechanism. Per the requester's
 * decision on G-14, expiry means **exit to the admin view**, not logout: the operator keeps
 * their own session and whatever else they had open, and only the borrowed identity is given
 * back.
 *
 * Elapsed time is read from the open `ImpersonationSession` row rather than from a session
 * key. The row is already the authoritative record of when the switch happened; a parallel
 * timestamp in the session would be a second source of truth that drifts the first time a
 * session is restored without it.
 */
final readonly class ImpersonationExpirySubscriber implements EventSubscriberInterface
{
    public const EXPIRED_MESSAGE = 'Impersonation ended automatically after the time limit. You are signed in as yourself again.';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UserRepository $users,
        private ImpersonationAuditRecorder $recorder,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private int $impersonationTtl,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 7 — below the firewall at 8 so a token exists, and level with
        // SessionIdleTimeoutSubscriber. The two cannot both fire: an idle session is signed
        // out entirely, which removes the token this one needs.
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (!$token instanceof SwitchUserToken) {
            return;
        }

        $original = $token->getOriginalToken();
        $tokenAdmin = $original->getUser();

        if (!$tokenAdmin instanceof User || null === $tokenAdmin->getId()) {
            return;
        }

        // Re-read rather than using the token's copy. Only the *switched* user is refreshed
        // through the provider on each request; the original token carries the instance that
        // was serialized into the session, which is detached by the time this runs — writing
        // an audit row against it makes Doctrine treat the admin as a new entity and abort.
        $admin = $this->users->find($tokenAdmin->getId());

        if (!$admin instanceof User) {
            return;
        }

        $session = $this->recorder->openSessionFor($admin);

        if (null === $session || $session->elapsedSeconds($this->clock->now()) < $this->impersonationTtl) {
            return;
        }

        $this->recorder->end($admin, ImpersonationEndReason::Expiry);

        // Restoring the original token is the whole exit: ContextListener serializes whatever
        // is in storage back into the session on the way out of this request, so the next one
        // arrives as the admin.
        $this->tokenStorage->setToken($original);

        $request = $event->getRequest();

        if ($request->hasSession() && ($requestSession = $request->getSession()) instanceof FlashBagAwareSessionInterface) {
            $requestSession->getFlashBag()->add('warning', self::EXPIRED_MESSAGE);
        }

        // Redirect rather than continuing: the request was aimed at a page the impersonated
        // user could see, and the admin often cannot — continuing would answer 403 to
        // somebody who did nothing wrong.
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_dashboard')));
    }
}
