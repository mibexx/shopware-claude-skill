<?php declare(strict_types=1);

namespace SwagExample\Event;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Symfony\Contracts\EventDispatcher\Event;

class ExampleEvent extends Event implements ShopwareEvent
{
    final public const EVENT_NAME = 'swag_example.example';

    public function __construct(
        private readonly string $payload,
        private readonly Context $context
    ) {
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
