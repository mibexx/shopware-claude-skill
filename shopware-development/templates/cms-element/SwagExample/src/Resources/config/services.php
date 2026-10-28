<?php declare(strict_types=1);

use Shopware\Core\Framework\Util\HtmlSanitizer;
use SwagExample\DataResolver\SwagExampleElementCmsElementResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SwagExampleElementCmsElementResolver::class)
        ->args([service(HtmlSanitizer::class)])
        ->tag('shopware.cms.data_resolver');
};
