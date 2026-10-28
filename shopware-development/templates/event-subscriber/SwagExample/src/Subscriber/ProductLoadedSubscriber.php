<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductLoadedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // 'product.loaded'; Format auch: Event => ['methode', prioritaet]
            ProductEvents::PRODUCT_LOADED_EVENT => 'onProductLoaded',
        ];
    }

    /**
     * @param EntityLoadedEvent<ProductEntity> $event
     */
    public function onProductLoaded(EntityLoadedEvent $event): void
    {
        // Leicht halten: laeuft bei jedem Laden von Produkten (auch Massenladungen, Admin-API).
        foreach ($event->getEntities() as $product) {
            $product->addExtension('swagExample', new ArrayStruct(['loaded' => true]));
        }
    }
}
