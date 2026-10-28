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

// Pfad im Plugin: custom/plugins/SwagExample/tests/Integration/Repository/ProductRepositoryTest.php
// Integrationstest ueber das DAL: echter Kernel + Test-DB (shopware_test), jeder Test in einer zurueckgerollten Transaktion.
// Der Zugriff laeuft ueber den Test-Container (static::getContainer()); dort sind auch private Services abrufbar,
// ABER nur solche, die im kompilierten Container noch existieren (ungenutzte private Services werden entfernt).
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
