<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

// Requires shopware/storefront (add it to require in composer.json, see README).
class ProductPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Page events are named like their class
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        // Twig: {{ page.extensions.swagHello.text }}
        $event->getPage()->addExtension('swagHello', new ArrayStruct(['text' => 'Hello']));
    }
}
