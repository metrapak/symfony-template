<?php

declare(strict_types=1);

namespace App\Approval\Twig;

use App\Account\Entity\User;
use App\Approval\Repository\ApprovalNotificationRepository;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the notification indicator needs to know about the viewer (FR-093).
 *
 * Functions rather than Twig globals, for the reason `ProfileExtension` gives: a global is
 * evaluated eagerly on every page, including the login form and the public redemption flow, and
 * both of these are queries.
 *
 * Two counts and not one, because they answer different questions and the indicator says both:
 * how many messages are unread, and how many purchases are actually waiting on this parent. A
 * parent who has read the emails still has three decisions to make.
 */
final class ApprovalExtension extends AbstractExtension
{
    public function __construct(
        private readonly ApprovalNotificationRepository $notifications,
        private readonly PurchaseApprovalRequestRepository $requests,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notification_count', $this->unreadNotificationCount(...)),
            new TwigFunction('pending_approval_count', $this->pendingApprovalCount(...)),
        ];
    }

    public function unreadNotificationCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notifications->countUnreadFor($user) : 0;
    }

    public function pendingApprovalCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->requests->countPendingForParent($user) : 0;
    }
}
