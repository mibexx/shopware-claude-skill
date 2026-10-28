<?php declare(strict_types=1);

namespace SwagExample\Decorator;

use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

// Dekoriert einen Core-Service (Store-API-Route). KEIN #[Route] am Decorator:
// Routen definiert nur der Core-Service.
class ProductListingRouteDecorator extends AbstractProductListingRoute
{
    public function __construct(private readonly AbstractProductListingRoute $inner)
    {
    }

    public function getDecorated(): AbstractProductListingRoute
    {
        return $this->inner;
    }

    public function load(string $categoryId, Request $request, SalesChannelContext $context, Criteria $criteria): ProductListingRouteResponse
    {
        // Beispiel: Criteria vor dem Aufruf anpassen
        $criteria->setLimit(12);

        return $this->getDecorated()->load($categoryId, $request, $context, $criteria);
    }
}
