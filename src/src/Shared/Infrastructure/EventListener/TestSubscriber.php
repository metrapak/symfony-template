<?php

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Infrastructure\Events\TestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TestSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            TestEvent::class => ['onTestEvent', 10],
        ];
    }

    public function onTestEvent(TestEvent $event): void
    {
        $test = $event->test();
    }
}
