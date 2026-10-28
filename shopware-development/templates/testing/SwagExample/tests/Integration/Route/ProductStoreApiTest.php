<?php declare(strict_types=1);

namespace SwagExample\Tests\Integration\Route;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

// Pfad im Plugin: custom/plugins/SwagExample/tests/Integration/Route/ProductStoreApiTest.php
// Store-API-Test: braucht symfony/browser-kit (siehe README.md). ProductBuilder/IdsCollection sind @internal bzw. @final.
class ProductStoreApiTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testProductIsReadableViaStoreApi(): void
    {
        $browser = $this->getSalesChannelBrowser(); // legt einen Test-Sales-Channel an und setzt sw-access-key
        $ids = new IdsCollection();

        (new ProductBuilder($ids, 'SW-QA'))
            ->price(100)
            ->visibility($this->getSalesChannelApiSalesChannelId()) // nicht TestDefaults::SALES_CHANNEL
            ->write(static::getContainer());

        $browser->request('POST', '/store-api/product', ['ids' => [$ids->get('SW-QA')]]);

        $response = \json_decode((string) $browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame(200, $browser->getResponse()->getStatusCode());
        static::assertSame($ids->get('SW-QA'), $response['elements'][0]['id']);
    }
}
