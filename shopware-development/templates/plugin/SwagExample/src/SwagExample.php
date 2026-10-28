<?php declare(strict_types=1);

namespace SwagExample;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class SwagExample extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        // Runs BEFORE the migrations. The plugin's own services are NOT available here.
    }

    public function postInstall(InstallContext $installContext): void
    {
        // Runs after migrations and after installedAt has been set.
    }

    public function update(UpdateContext $updateContext): void
    {
        // Runs BEFORE the update's migrations. Only one-off data adjustments,
        // schema changes belong in migrations.
        // $updateContext->getCurrentPluginVersion() = previously installed version
        // $updateContext->getUpdatePluginVersion()  = target version
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    public function activate(ActivateContext $activateContext): void
    {
        // The plugin is installed; the kernel has been rebooted with the plugin.
        // Migrations do NOT run here (autoMigrate is false; only with setAutoMigrate(true) in activate()).
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // The core does not delete plugin tables. Order: dependent tables first.
        // Tables only exist if the template templates/migration (or entity) is used.
        // $this->container is typed nullable (PHPStan level 8: method.nonObject), hence a runtime check instead of @var.
        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        $connection->executeStatement('DROP TABLE IF EXISTS `swag_example_translation`');
        $connection->executeStatement('DROP TABLE IF EXISTS `swag_example`');
    }

    // Optional overrides (for defaults see core/Framework/Plugin.php):
    // public function executeComposerCommands(): bool { return true; } // composer require/remove on install/uninstall
    // public function rebuildContainer(): bool { return false; }        // only effective in the CLI
    // public function enrichPrivileges(): array { return []; }          // additional ACL privileges
}
