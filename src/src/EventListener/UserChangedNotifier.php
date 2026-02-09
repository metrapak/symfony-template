<?php

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate')]
class UserChangedNotifier
{
    public function postUpdate(PostUpdateEventArgs $event): void
    {
        // ... do something to notify the changes
    }
}
