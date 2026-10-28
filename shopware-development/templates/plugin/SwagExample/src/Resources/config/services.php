<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    // Explizite Registrierung (Core-Stil): kein Autowiring, Argumente und Tags werden angegeben.
    // Beispiel:
    // $services->set(\SwagExample\Service\MyService::class)
    //     ->args([service(\Shopware\Core\System\SystemConfig\SystemConfigService::class)]);
    //
    // Alternative mit Autowiring/Autoconfigure: siehe README.md (Variante B).
};
