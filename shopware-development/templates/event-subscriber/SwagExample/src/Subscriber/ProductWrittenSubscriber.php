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
            // 'product.written': erst NACH dem Schreiben
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            // klassenbasiertes Event (Namespace ...\Write\Validation): innerhalb der Transaktion, nur validieren
            PreWriteValidationEvent::class => 'onPreWrite',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        // Achtung Endlosschleife: nicht hier erneut 'product' schreiben ohne Guard.
        $this->logger->info('Products written', ['ids' => $event->getIds()]);
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommandsForEntity(ProductDefinition::ENTITY_NAME) as $command) {
            if ($command instanceof InsertCommand) {
                // $command->getPayload() pruefen; Fehler per $event->getExceptions()->add(\Throwable) anhaengen.
                // Keine Repository-Schreibzugriffe hier (laufende Transaktion).
            }
        }
    }
}
