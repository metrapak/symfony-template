<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;

/**
 * Removes the personal data outside the `user` row that a GDPR erasure must take with it
 * (FR-025, G-19).
 *
 * G-19 is the gap this closes. FR-025 requires "photo → default avatar" and "personal
 * identifiers → NULL", and when TASK-002 built the erasure there was no photo column, no coach
 * bio and no emergency contact to clear — so the requirement was recorded as unmet rather than
 * pretended satisfied. TASK-004 added those columns, and this is the seam through which they get
 * cleared.
 *
 * An interface for the same reason as `CoachOrganizationProvider`: the columns belong to the
 * Profile module, which depends on Account, while `UserAnonymizer` lives in Account. Depending
 * back on Profile's repositories from here would close the loop and make the two modules one.
 * Account declares what an erasure must reach; Profile knows where it is.
 *
 * The two methods exist because an erasure has two halves with different failure modes.
 * `erasePersonalDataFor()` mutates rows and must **persist without flushing**, like
 * `AuditLogger`, so it commits or rolls back with the anonymization it belongs to.
 * `deleteOrphanedFiles()` unlinks files, which no transaction can undo, so it runs only after
 * that commit — and it is a separate call rather than something the first method does, because
 * the first method has no way of knowing whether the transaction around it will succeed.
 */
interface PersonalDataEraser
{
    /**
     * @return list<string> stored file paths the caller passes to `deleteOrphanedFiles()` once
     *                      the erasure has committed
     */
    public function erasePersonalDataFor(User $user): array;

    public function deleteOrphanedFiles(string ...$paths): void;
}
