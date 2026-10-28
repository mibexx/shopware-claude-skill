<?php declare(strict_types=1);

namespace SwagExample\Tests\Integration\Repository;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

// Path in the plugin: custom/plugins/SwagExample/tests/Integration/Repository/ProductRepositoryTest.php
// Integration test via the DAL: real kernel + test DB (shopware_test), each test in a rolled-back transaction.
// Access goes through the test container (static::getContainer()); private services can be retrieved there too,
// BUT only those that still exist in the compiled container (unused private services are removed).
class ProductRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testWrittenProductCanBeSearchedByProductNumber(): void
    {
        $ids = new IdsCollection();

        (new ProductBuilder($ids, 'SW-QA-DAL'))
            ->price(10)
            ->write(static::getContainer());

        /** @var EntityRepository<ProductCollection> $repository */
        $repository = static::getContainer()->get('product.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', 'SW-QA-DAL'));

        $result = $repository->searchIds($criteria, Context::createDefaultContext());

        static::assertSame([$ids->get('SW-QA-DAL')], $result->getIds());
    }
}
