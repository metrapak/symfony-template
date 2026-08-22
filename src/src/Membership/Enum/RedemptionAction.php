<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * What `/join/{code}` should do for the visitor in front of it.
 *
 * The whole decision table lives in one enum so the controller dispatches rather than
 * reasons. Every branch of the spec's redemption rules is a case here: anonymous visitors
 * register, existing players pick who is joining, coaches accept, children are refused
 * (BR-046), and everybody else — a trainer, a Super Admin, a player holding a coach
 * invitation — is told plainly that this link is not for them.
 */
enum RedemptionAction: string
{
    case RegisterPlayer = 'register_player';
    case RegisterCoach = 'register_coach';
    case AssociatePlayer = 'associate_player';
    case AcceptCoachInvitation = 'accept_coach_invitation';
    case BlockChild = 'block_child';
    case NotEligible = 'not_eligible';
}
