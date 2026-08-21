<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Service\LoginRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Stamps `lastLoginAt` once authentication has succeeded.
 *
 * An adapter, not a workflow: the write itself belongs to LoginRecorder. Listening on
 * LoginSuccessEvent rather than doing this in the login controller means every present and
 * future authenticator on the firewall is covered, and that the stamp is only written after
 * the user checker has had its say — a refused account has not logged in.
 */
final readonly class RecordLastLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoginRecorder $loginRecorder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $this->loginRecorder->recordSuccessfulLogin($user);
    }
}
