<?php declare(strict_types=1);

namespace SwagExample\Decorator;

use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

// Decorates a core service (Store API route). NO #[Route] on the decorator:
// only the core service defines routes.
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
        // Example: adjust criteria before the call
        $criteria->setLimit(12);

        return $this->getDecorated()->load($categoryId, $request, $context, $criteria);
    }
}
