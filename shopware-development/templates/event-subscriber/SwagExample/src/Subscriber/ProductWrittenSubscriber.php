<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // 'product.written': only AFTER writing
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            // class-based event (namespace ...\Write\Validation): inside the transaction, validate only
            PreWriteValidationEvent::class => 'onPreWrite',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        // Beware of infinite loops: do not write 'product' again here without a guard.
        $this->logger->info('Products written', ['ids' => $event->getIds()]);
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommandsForEntity(ProductDefinition::ENTITY_NAME) as $command) {
            if ($command instanceof InsertCommand) {
                // check $command->getPayload(); attach errors via $event->getExceptions()->add(\Throwable).
                // No repository writes here (transaction in progress).
            }
        }
    }
}
