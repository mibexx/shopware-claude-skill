<?php declare(strict_types=1);

// Pfad im Plugin: custom/plugins/SwagExample/tests/TestBootstrap.php

use Shopware\Core\TestBootstrapper;

// Plugin liegt in custom/plugins/<Name> des Shopware-Projekts. Das Projekt-Root wird ueber
// PROJECT_ROOT oder durch Hochlaufen bis zum Verzeichnis mit vendor/ gefunden (siehe TestBootstrapper::getProjectDir()).
$bootstrapper = (new TestBootstrapper())
    ->setPlatformEmbedded(true)
    ->addCallingPlugin()              // liest extra.shopware-plugin-class aus der composer.json des Plugins
    ->setForceInstallPlugins(true)    // plugin:refresh + plugin:install --activate --reinstall bei jedem Lauf
    ->bootstrap();

// Das autoload-dev des Plugins wertet der Projekt-Composer nicht aus: Test-Namespace selbst registrieren.
$bootstrapper->getClassLoader()->addPsr4('SwagExample\\Tests\\', __DIR__);
