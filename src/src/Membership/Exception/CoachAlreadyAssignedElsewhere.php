<?php

declare(strict_types=1);

namespace App\Membership\Exception;

/**
 * FR-045 / BR-044: a coach may be active under exactly one trainer at a time.
 *
 * The message names no organization on purpose. Telling an invited coach *which* other
 * trainer holds them would leak one tenant's roster into another's onboarding flow; the
 * trainer who sent the invitation is told the same thing.
 */
final class CoachAlreadyAssignedElsewhere extends \DomainException implements MembershipException
{
    public static function forCoach(string $email, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('%s is already an active coach for another trainer and cannot join a second organization.', $email),
            0,
            $previous,
        );
    }
}
