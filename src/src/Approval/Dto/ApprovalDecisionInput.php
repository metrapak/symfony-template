<?php

declare(strict_types=1);

namespace App\Approval\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a parent submits with a decision (BR-093).
 *
 * One optional field, and it is optional on purpose: BR-093 says a parent *may* add notes, and a
 * required explanation would either be skipped with a full stop or would stop parents deciding at
 * all — the opposite of what FR-096's 48-hour clock needs. The cap keeps a note a note; the
 * reasoning behind a decision belongs in a sentence, and the column behind it is read in a list.
 */
final class ApprovalDecisionInput
{
    #[Assert\Length(
        max: 2000,
        maxMessage: 'Keep your note under {{ limit }} characters.',
    )]
    public ?string $notes = null;
}
