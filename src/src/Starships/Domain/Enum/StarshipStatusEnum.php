<?php

namespace App\Starships\Domain\Enum;

enum StarshipStatusEnum: string
{
    case WAITING = 'waiting';
    case IN_PROGRESS = 'in progress';
    case COMPLETED = 'completed';
}
