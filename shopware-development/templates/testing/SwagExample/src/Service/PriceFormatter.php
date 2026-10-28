<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

// Pfad im Plugin: custom/plugins/SwagExample/src/Service/PriceFormatter.php (nur Testobjekt der Beispiele)
class PriceFormatter
{
    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function format(float $price): string
    {
        return \sprintf('%.2F %s', $price, $this->config->getString('SwagExample.config.suffix'));
    }
}
