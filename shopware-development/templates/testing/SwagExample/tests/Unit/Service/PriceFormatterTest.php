<?php declare(strict_types=1);

namespace SwagExample\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use SwagExample\Service\PriceFormatter;

// Pfad im Plugin: custom/plugins/SwagExample/tests/Unit/Service/PriceFormatterTest.php
// Unit-Test: kein Kernel, keine Datenbank, keine Test-Traits.
#[CoversClass(PriceFormatter::class)]
class PriceFormatterTest extends TestCase
{
    public function testUsesConfiguredSuffix(): void
    {
        $config = new StaticSystemConfigService(['SwagExample.config.suffix' => 'EUR']);

        static::assertSame('9.90 EUR', (new PriceFormatter($config))->format(9.9));
    }
}
