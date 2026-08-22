<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * The gender recorded on a player profile (spec §8, "For Player Profiles").
 *
 * The spec asks for the field and never enumerates its values, so this is the smallest set
 * that keeps the data reportable without forcing anybody to answer: TASK-004 owns the profile
 * screens and may extend it. `Undisclosed` exists so the column can stay meaningful when a
 * parent declines — an absent value and a declined one are different facts.
 */
enum PlayerGender: string
{
    case Female = 'female';
    case Male = 'male';
    case Other = 'other';
    case Undisclosed = 'undisclosed';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::Other => 'Other',
            self::Undisclosed => 'Prefer not to say',
        };
    }
}
