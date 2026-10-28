<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    // Explicit registration (core style): no autowiring, arguments and tags are specified.
    // Example:
    // $services->set(\SwagExample\Service\MyService::class)
    //     ->args([service(\Shopware\Core\System\SystemConfig\SystemConfigService::class)]);
    //
    // Alternative with autowiring/autoconfigure: see README.md (variant B).
};
