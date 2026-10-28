<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Context;
use SwagExample\Event\ExampleEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ExampleService
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function run(string $payload, Context $context): void
    {
        // Dispatch with a fixed name, then subscribers can use ExampleEvent::EVENT_NAME.
        // Without the second parameter the event name is the class name (ExampleEvent::class).
        $this->eventDispatcher->dispatch(new ExampleEvent($payload, $context), ExampleEvent::EVENT_NAME);
    }
}
