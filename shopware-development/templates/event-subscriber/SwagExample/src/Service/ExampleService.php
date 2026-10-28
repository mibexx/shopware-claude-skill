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
        // Mit festem Namen dispatchen, dann koennen Subscriber ExampleEvent::EVENT_NAME verwenden.
        // Ohne zweiten Parameter ist der Eventname der Klassenname (ExampleEvent::class).
        $this->eventDispatcher->dispatch(new ExampleEvent($payload, $context), ExampleEvent::EVENT_NAME);
    }
}
