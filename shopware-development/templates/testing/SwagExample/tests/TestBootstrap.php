<?php declare(strict_types=1);

// Path in the plugin: custom/plugins/SwagExample/tests/TestBootstrap.php

use Shopware\Core\TestBootstrapper;

// The plugin lives in custom/plugins/<Name> of the Shopware project. The project root is found via
// PROJECT_ROOT or by walking up to the directory containing vendor/ (see TestBootstrapper::getProjectDir()).
$bootstrapper = (new TestBootstrapper())
    ->setPlatformEmbedded(true)
    ->addCallingPlugin()              // reads extra.shopware-plugin-class from the plugin's composer.json
    ->setForceInstallPlugins(true)    // plugin:refresh + plugin:install --activate --reinstall on every run
    ->bootstrap();

// The project Composer does not evaluate the plugin's autoload-dev: register the test namespace yourself.
$bootstrapper->getClassLoader()->addPsr4('SwagExample\\Tests\\', __DIR__);
