<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

// Benoetigt shopware/storefront (in composer.json require ergaenzen, siehe README).
class ProductPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Page-Events heissen wie ihre Klasse
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        // Twig: {{ page.extensions.swagHello.text }}
        $event->getPage()->addExtension('swagHello', new ArrayStruct(['text' => 'Hallo']));
    }
}
