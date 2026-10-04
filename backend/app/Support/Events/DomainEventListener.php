<?php

namespace App\Support\Events;

interface DomainEventListener
{
    public function handle(DomainEvent $event): void;
}
