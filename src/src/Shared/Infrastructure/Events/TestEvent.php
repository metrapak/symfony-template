<?php

namespace App\Shared\Infrastructure\Events;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * This event is dispatched each time an order
 * is placed in the system.
 */
final class TestEvent extends Event
{
    public function test(): string
    {
        return 'This is a test event';
    }
}
