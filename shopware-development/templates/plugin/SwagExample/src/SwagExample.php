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
        // Laeuft VOR den Migrationen. Eigene Plugin-Services sind hier NICHT verfuegbar.
    }

    public function postInstall(InstallContext $installContext): void
    {
        // Laeuft nach Migrationen und nach dem Setzen von installedAt.
    }

    public function update(UpdateContext $updateContext): void
    {
        // Laeuft VOR den Migrationen des Updates. Nur einmalige Datenanpassungen,
        // Schemaaenderungen gehoeren in Migrationen.
        // $updateContext->getCurrentPluginVersion() = bisher installierte Version
        // $updateContext->getUpdatePluginVersion()  = Zielversion
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Plugin ist installiert; Kernel wurde mit dem Plugin neu gebootet.
        // Migrationen laufen hier NICHT (autoMigrate ist false; nur mit setAutoMigrate(true) in activate()).
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

        // Der Core loescht keine Plugin-Tabellen. Reihenfolge: abhaengige Tabellen zuerst.
        // Tabellen existieren nur, wenn die Vorlage templates/migration (bzw. entity) verwendet wird.
        // $this->container ist typisiert nullable (PHPStan Level 8: method.nonObject), daher Laufzeit-Check statt @var.
        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        $connection->executeStatement('DROP TABLE IF EXISTS `swag_example_translation`');
        $connection->executeStatement('DROP TABLE IF EXISTS `swag_example`');
    }

    // Optionale Ueberschreibungen (Defaults siehe core/Framework/Plugin.php):
    // public function executeComposerCommands(): bool { return true; } // composer require/remove bei Install/Uninstall
    // public function rebuildContainer(): bool { return false; }        // nur CLI wirksam
    // public function enrichPrivileges(): array { return []; }          // zusaetzliche ACL-Privilegien
}
