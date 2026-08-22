<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Enum\ImpersonationEndReason;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\CannotImpersonate;
use App\Account\Service\ImpersonationAuditRecorder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * The single point where impersonation is authorized and recorded (FR-030, FR-032, BR-021).
 *
 * It listens on the security event rather than sitting in the controller because
 * `SwitchUserListener` will act on `?_switch_user=` appended to *any* URL. A rule enforced
 * only in `ImpersonationController` would be a rule anyone could skip by editing the address
 * bar; a rule enforced here holds for every path into the switch.
 *
 * Throwing `AccessDeniedException` from this listener produces a 403:
 * `SwitchUserListener::authenticate()` wraps the switch in a `catch (AuthenticationException)`
 * only, and `AccessDeniedException` is not one, so it propagates instead of being converted
 * into a generic failure.
 */
final readonly class SwitchUserAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ImpersonationAuditRecorder $recorder,
        private Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SecurityEvents::SWITCH_USER => 'onSwitchUser'];
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $token = $event->getToken();

        // A SwitchUserToken means a switch is starting; anything else means the original
        // token is being restored, which is an exit.
        if ($token instanceof SwitchUserToken) {
            $this->onStart($event);

            return;
        }

        $this->onExit($event);
    }

    private function onStart(SwitchUserEvent $event): void
    {
        $target = $event->getTargetUser();

        // The admin is still the current identity at this point: Symfony dispatches this
        // event before writing the new token into storage.
        $admin = $this->security->getUser();

        if (!$admin instanceof User || !$target instanceof User) {
            throw new AccessDeniedException('Impersonation requires an application user on both sides.');
        }

        if (UserRole::SuperAdmin !== $admin->getRole()) {
            throw new AccessDeniedException('Only a Super Admin may impersonate.');
        }

        if (UserRole::SuperAdmin === $target->getRole()) {
            throw new AccessDeniedException(CannotImpersonate::superAdminTarget()->getMessage());
        }

        if (UserStatus::Deleted === $target->getStatus()) {
            throw new AccessDeniedException(CannotImpersonate::deletedTarget()->getMessage());
        }

        $this->recorder->start($admin, $target);
    }

    private function onExit(SwitchUserEvent $event): void
    {
        $admin = $event->getTargetUser();

        if (!$admin instanceof User) {
            return;
        }

        $this->recorder->end($admin, ImpersonationEndReason::Exit);
    }
}
