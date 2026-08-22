<?php

declare(strict_types=1);

namespace App\Approval\Service;

use App\Account\Entity\User;
use App\Approval\Entity\ApprovalNotification;
use App\Approval\Repository\ApprovalNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reading the in-app notifications, and marking them read (FR-093, G-33).
 *
 * Marking read is an explicit action with its own POST rather than a side effect of opening the
 * page. A GET that writes is a GET a browser may repeat, a prefetch may trigger and a crawler may
 * follow — and here it would silently clear the indicator that tells a parent somebody is waiting
 * on them.
 */
final readonly class NotificationInbox
{
    public function __construct(
        private ApprovalNotificationRepository $notifications,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<ApprovalNotification>
     */
    public function recentFor(User $recipient): array
    {
        return $this->notifications->recentFor($recipient);
    }

    public function unreadCountFor(User $recipient): int
    {
        return $this->notifications->countUnreadFor($recipient);
    }

    /**
     * @return int how many were marked, for the message the reader gets back
     */
    public function markAllRead(User $recipient): int
    {
        $now = $this->clock->now();
        $unread = $this->notifications->unreadFor($recipient);

        foreach ($unread as $notification) {
            $notification->markRead($now);
        }

        $this->entityManager->flush();

        return \count($unread);
    }
}
