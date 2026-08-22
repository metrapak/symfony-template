<?php

declare(strict_types=1);

namespace App\Approval\Exception;

/**
 * Marker for every failure the Approval module raises deliberately.
 *
 * Controllers branch on these rather than on Doctrine or bundle-internal types, so the approval
 * screens keep their HTTP behaviour when the persistence details change. Mirrors the convention
 * TASK-003 established for `MembershipException` and TASK-004 for `ProfileException`.
 */
interface ApprovalException extends \Throwable
{
}
