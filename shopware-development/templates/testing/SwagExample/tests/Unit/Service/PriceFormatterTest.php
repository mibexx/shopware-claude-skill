<?php declare(strict_types=1);

namespace SwagExample\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use SwagExample\Service\PriceFormatter;

// Path in the plugin: custom/plugins/SwagExample/tests/Unit/Service/PriceFormatterTest.php
// Unit test: no kernel, no database, no test traits.
#[CoversClass(PriceFormatter::class)]
class PriceFormatterTest extends TestCase
{
    public function testUsesConfiguredSuffix(): void
    {
        $config = new StaticSystemConfigService(['SwagExample.config.suffix' => 'EUR']);

        static::assertSame('9.90 EUR', (new PriceFormatter($config))->format(9.9));
    }
}
