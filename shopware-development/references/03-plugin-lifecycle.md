# Plugin-Lifecycle (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin bei Installation, Update, Aktivierung, Deaktivierung oder Deinstallation Code ausführen oder aufräumen muss, und wenn man die zugehörigen CLI-Befehle und Events braucht. Maßgeblich ist `PluginLifecycleService` (Ist-Zustand 6.7.15.1).

Abgrenzung: Pflichtfelder und Composer in [T-02 Plugin-Aufbau](./02-plugin-aufbau.md); Schemaänderungen in [T-04 Migrationen](./04-datenbank-migrationen.md); Event-Subscriber allgemein in [T-07](./07-events-subscriber.md); Service-Registrierung in [T-05](./05-services-registrieren.md).

## 2. Konzepte

- `Shopware\Core\Framework\Plugin` bietet sieben leere, überschreibbare Hooks, je mit einem Context: `install`, `postInstall`, `update`, `postUpdate`, `activate`, `deactivate`, `uninstall`. Aufgerufen werden sie nur vom `PluginLifecycleService` (`@internal`), genutzt von CLI, Administration (Extension-Manager, `_action/extension/...`) und Store-Download.
- Context-Hierarchie (`core/Framework/Plugin/Context/`): `InstallContext` ist die Basis; `UpdateContext`, `UninstallContext`, `ActivateContext`, `DeactivateContext` erben davon.

| Methode | Klasse | Bedeutung |
|---|---|---|
| `getPlugin()` | `InstallContext` | Plugin-Basisklasse (nicht die Entity) |
| `getContext()` | `InstallContext` | Shopware-`Context` (CLI: `Context::createCLIContext()`, SystemSource) |
| `getCurrentShopwareVersion()` | `InstallContext` | Shopware-Version |
| `getCurrentPluginVersion()` | `InstallContext` | bei Update die bisher installierte Version |
| `getMigrationCollection()` | `InstallContext` | `MigrationCollection` des Plugins |
| `isAutoMigrate()` / `setAutoMigrate(bool)` | `InstallContext` | Install/Update Standard true (Activate/Deactivate/Uninstall setzt der Core auf false); auf false setzen und `getMigrationCollection()->migrateInPlace()` selbst steuern |
| `getUpdatePluginVersion()` | `UpdateContext` | Zielversion |
| `keepUserData()` | `UninstallContext` | true = „Daten behalten" (Admin: `keepUserData: !removeData`, CLI: `--keep-user-data`) |

`ActivateContext` und `DeactivateContext` sind leere Subklassen.

### Verzeichnisbaum

```text
custom/plugins/SwagExample/src/
├── SwagExample.php                    # Hooks
├── Migration/Migration1730000000CreateExampleTable.php   # laufen bei install/update
├── Subscriber/PluginLifecycleSubscriber.php              # optional: Plugin*Event
└── Resources/config/{services.php,config.xml,custom-fields.xml}
```

`config.xml` wird bei install/update als Systemconfig-Default gespeichert; `custom-fields.xml` wird synchronisiert und bei Uninstall (ohne `keepUserData`) entfernt.

### Ablauf je Phase (`PluginLifecycleService`, Zeilennummern für 6.7.15.1)

**installPlugin** (Z. 114-190): (1) `installedAt` bereits gesetzt -> no-op. (2) Anforderungen prüfen (`RequirementsValidator`) bzw. bei `executeComposerCommands()` `composer require`. (3) `PluginPreInstallEvent`. (4) `SystemConfigService::savePluginConfiguration($plugin, true)`. (5) `install()`. (6) Migrationen (nur bei `isAutoMigrate()`). (7) Custom Fields. (8) `installedAt` in DB. (9) `postInstall()`. (10) `PluginPostInstallEvent`. Fehler: ggf. `composer remove`; ist `installedAt` schon gesetzt (Fehler in `postInstall`/Post-Event), folgt `uninstallPlugin(keepUserData: true)`. Fehler in `install()` oder Migrationen führen **nicht** zu DB-Rollback.

**activatePlugin** (Z. 352-436): nicht installiert -> Exception; bereits aktiv -> no-op. Anforderungen prüfen, `PluginPreActivateEvent`, Kernel-Reboot mit aktivem Plugin (`rebuildContainerWithNewPluginState`; in CLI nur wenn `rebuildContainer()===true`, Default true; bei Aufruf aus der Administration immer), neuer `ActivateContext` mit `setAutoMigrate(false)`, `activate()` auf der neuen Instanz, `runMigrations()` (läuft nur, wenn `activate()` selbst `setAutoMigrate(true)` gesetzt hat), Assets kopieren, `active=true`, `PluginPostActivateEvent` (wirft ein Listener, wird `active` zurückgesetzt).

**deactivatePlugin** (Z. 438-526): nicht installiert/nicht aktiv -> Exception; aktive abhängige Plugins blockieren. `PluginPreDeactivateEvent`, `deactivate()`, Assets entfernen, `active=false`, Kernel-Reboot, DB-Update. Bei Exception `PluginPostDeactivationFailedEvent`, bei Erfolg `PluginPostDeactivateEvent`.

**updatePlugin** (Z. 267-350): muss installiert sein; `getUpdatePluginVersion()` = `upgradeVersion ?? version`. `PluginPreUpdateEvent`, Systemconfig speichern (bestehende Werte nicht überschreiben), `update()`; wirft `update()`, wird ein aktives Plugin deaktiviert und die Exception weitergeworfen. Danach Assets (nur aktiv, ohne Skip-State), Migrationen, Custom Fields (`deleteMissingXml=true`: ohne XML werden alle Sets des Plugins entfernt), DB-Version, `postUpdate()`, `PluginPostUpdateEvent`.

**uninstallPlugin** (Z. 194-262): aktives Plugin wird zuerst deaktiviert (inkl. Hooks/Events). `PluginPreUninstallEvent`, Assets entfernen. Ohne `keepUserData`: `Plugin::removeMigrations()` löscht Zeilen aus `migration` (VOR `uninstall()`, damit Migrationen bei Fehler erneut laufen). `uninstall()` (Context mit `autoMigrate=false`). Ohne `keepUserData`: Systemconfig des Plugins löschen. DB: `active=false`, `installedAt=null`. Ohne `keepUserData`: Custom Entities und Custom Fields entfernen. Bei `executeComposerCommands()`: `composer remove`. `PluginPostUninstallEvent`.

Der Core räumt **nicht** auf: Plugin-Tabellen und -Spalten sowie angelegte Zahlungsarten, Regeln, Mail-Templates. Das muss `uninstall()` selbst tun (Doku: nicht blind Entities löschen, z. B. Zahlungsart in historischen Bestellungen; besser deaktivieren).

### Events (`core/Framework/Plugin/Event/`, alle erben `PluginLifecycleEvent`, `getPlugin(): PluginEntity`)

| Event | Zeitpunkt | `getContext()` |
|---|---|---|
| `PluginPreInstallEvent` / `PluginPostInstallEvent` | vor `install()` / nach `postInstall()` | `InstallContext` |
| `PluginPreUpdateEvent` / `PluginPostUpdateEvent` | vor `update()` / nach `postUpdate()` | `UpdateContext` |
| `PluginPreActivateEvent` / `PluginPostActivateEvent` | vor Reboot und `activate()` / nach `active=true` | `ActivateContext` |
| `PluginPreDeactivateEvent` / `PluginPostDeactivateEvent` | vor `deactivate()` / nach Deaktivierung | `DeactivateContext` |
| `PluginPostDeactivationFailedEvent` | Exception bei Deaktivierung (`getException(): ?\Throwable`) | `ActivateContext` |
| `PluginPreUninstallEvent` / `PluginPostUninstallEvent` | vor Asset-Entfernung / nach DB-Update und Composer-Remove | `UninstallContext` |

Abonniert wird per FQCN. `PluginEvents` enthält nur DAL-Events der Plugin-Entity (`plugin.written`, `plugin.loaded` ...), keine Lifecycle-Events. Core-Subscriber darauf: `storefront/Theme/Subscriber/PluginLifecycleSubscriber.php` (Theme-Kompilierung), `core/Framework/MessageQueue/Subscriber/PluginLifecycleSubscriber.php` (Scheduled Tasks), `RegisteredIndexerSubscriber` (Indexer).

## 3. Anleitung

1. Anlegen und erkennen: `bin/console plugin:create SwagExample Swag`, dann `bin/console plugin:refresh` (`-s` unterdrückt die Liste). Für bereits installierte Plugins mit höherer Version auf der Platte wird `upgradeVersion` gesetzt (DB-`version` bleibt); für nicht installierte wird `version` überschrieben.
2. `bin/console plugin:list` zeigt Version, Upgrade-Version, Installiert/Aktiv.
3. Gemeinsame Optionen (`AbstractPluginLifecycleCommand`): Argument `plugins` (mehrere; exakter Namens-Treffer, sonst `ContainsFilter`), `-r/--refresh` (führt vorher `plugin:refresh -s` aus), `-c/--clearCache`, `--skip-asset-build` (Context-State `skip-asset-building`: überspringt Assets und Theme-Kompilierung).

| Befehl | Besonderheiten |
|---|---|
| `plugin:install <p>` | `-a/--activate` aktiviert direkt. `--reinstall` deinstalliert zuvor **ohne** `--keep-user-data` (Daten weg!). `-a` zusammen mit `-r`: Aktivierung übersprungen („Can not refresh and activate in same request"). Bereits installierte werden übersprungen (mit `-a` aktiviert). Abhängigkeiten via `sortPluginsByRequirements`. |
| `plugin:activate <p>` | `PluginNotInstalledException`, wenn nicht installiert |
| `plugin:deactivate <p>` | wirft, wenn nicht aktiv oder aktive Abhängige existieren (`PluginException::hasActiveDependants`) |
| `plugin:update <p>` | aktualisiert auf `upgradeVersion` |
| `plugin:update:all` | `refreshPlugins`, dann nur Plugins mit `upgradeVersion !== null` **und aktiv**; nur Option `--skip-asset-build` |
| `plugin:uninstall <p>` | `--keep-user-data`; aktives Plugin wird zuvor deaktiviert |

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console plugin:update SwagExample -c        # nach Versionserhöhung in composer.json + refresh
bin/console plugin:deactivate SwagExample
bin/console plugin:uninstall SwagExample --keep-user-data
bin/console plugin:uninstall SwagExample        # löscht Daten
```

4. Update-Ablauf: Version in `composer.json` erhöhen, `plugin:refresh` (erzeugt `upgradeVersion`), `plugin:update`.
5. Aufräum-Regeln: Schemaänderungen immer per Migration; `update()` nur für einmalige Datenanpassungen. `uninstall()` mit `if ($uninstallContext->keepUserData()) { return; }` beginnen. Zugefügte Spalten/Tabellen erst danach löschen, bei Fremdschlüsseln auf Core-Tabellen zuerst Constraints droppen. Zahlungsarten o. ä. in `deactivate()` auf inaktiv, in `activate()` wieder aktiv setzen. Cache nach `plugin:*` mit `-c` leeren.

## 4. Beispiele

`custom/plugins/SwagExample/src/SwagExample.php`

```php
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
        // Läuft VOR den Migrationen; Plugin-Services sind hier nicht verfügbar.
    }

    public function postInstall(InstallContext $installContext): void
    {
        // Nach Migrationen und nach Setzen von installedAt.
    }

    public function update(UpdateContext $updateContext): void
    {
        // Läuft VOR den Migrationen des Updates. Schemaänderungen gehören in Migrationen.
        if (version_compare($updateContext->getCurrentPluginVersion(), '1.1.0', '<')) {
            // einmalige Datenanpassung beim Update von < 1.1.0
        }
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Plugin-Services verfügbar (Kernel wurde neu gebootet).
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return; // Tabellen und Daten behalten
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `swag_example`');
    }
}
```

`src/Migration/Migration1730000000CreateExampleTable.php` (Details in [T-04](./04-datenbank-migrationen.md); Gerüst [../templates/migration/README.md](../templates/migration/README.md))

```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000000CreateExampleTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `swag_example` (
                `id` BINARY(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }
}
```

Optionaler Subscriber `src/Subscriber/PluginLifecycleSubscriber.php`:

```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginLifecycleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [PluginPostActivateEvent::class => 'onPostActivate'];
    }

    public function onPostActivate(PluginPostActivateEvent $event): void
    {
        $event->getPlugin()->getName(); // PluginEntity; $event->getContext() ist ein ActivateContext
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Subscriber\PluginLifecycleSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(PluginLifecycleSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

Der Subscriber des Plugins selbst existiert im Container nur, wenn das Plugin aktiv ist; Events des eigenen Plugins vor der Aktivierung sind nicht zuverlässig.

## 5. Fallstricke

1. Eigene Services in `install()` verwenden: Das Plugin ist noch nicht aktiv, der Container enthält seine Services nicht. Nur Core-Services via `$this->container->get(...)`.
2. Schema in `install()`/`update()` per SQL statt per Migration (Mehrfachausführung, keine Nachvollziehbarkeit).
3. `keepUserData()` ignorieren und alles löschen (Datenverlust, kaputte Bestellungen). `uninstall()` läuft **immer**, auch mit `keepUserData`.
4. Nicht idempotenter Lifecycle-Code/Migrationen: kein automatischer Rollback; Retry muss funktionieren (`IF NOT EXISTS`, `INSERT IGNORE`).
5. `plugin:update:all` aktualisiert nur **aktive** Plugins.
6. Version erhöht, aber `plugin:refresh` vergessen -> keine `upgradeVersion`.
7. `plugin:install --reinstall` erwartet Datenerhalt: es wird ohne `keepUserData` deinstalliert.
8. Migrationen bei `activate()` erwartet: dort ist `autoMigrate` per Default false (Core setzt es vor `activate()`); nur mit `setAutoMigrate(true)` in `activate()` laufen sie.
9. Lange Operationen in `activate()`/`deactivate()`: der Kernel-Reboot kostet Zeit. `rebuildContainer()` darf nur `false` liefern, wenn das Plugin seine Services bei Aktivierung nicht braucht (wirkt nur in CLI).
10. Konstruktor/`isActive()` der Plugin-Klasse überschreiben: beide `final`.
11. `PluginLifecycleService` direkt nutzen: `@internal`, keine API-Stabilität.
12. Context-Source: In der Administration läuft `activate` mit Admin-Source und erzwingt den Reboot, in der CLI mit `SystemSource`.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- An Hooks, Contexts und Events ist in 6.7.15.1 nichts `@deprecated` (per grep geprüft).
- Deprecated mit Entfernung in 6.8.0: `plugin:list --json` (stattdessen `--format json`), `AbstractPluginLifecycleCommand::handleClearCacheOption()` (Ersatz `handleClearCache()`).
- Lifecycle-Events werden per FQCN abonniert; es gibt keine String-Konstanten in `PluginEvents`.

## 6. Checkliste

- [ ] `uninstall()` prüft zuerst `keepUserData()`.
- [ ] Schemaänderungen nur in Migrationen, idempotent.
- [ ] `update()` enthält nur einmalige Datenanpassungen, versionsabhängig über `getCurrentPluginVersion()`.
- [ ] Keine Plugin-Services in `install()` verwendet.
- [ ] Zahlungsarten/Regeln/Mail-Templates werden deaktiviert statt gelöscht.
- [ ] Nach Versionserhöhung `plugin:refresh` dann `plugin:update`.
- [ ] Zugefügte Spalten in Core-Tabellen und Fremdschlüssel in `uninstall()` entfernt.
- [ ] Cache nach Lifecycle-Befehlen geleert (`-c`).
- [ ] Lifecycle-Events per FQCN abonniert, Subscriber in `services.php` registriert.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/plugin-lifecycle.html
- https://developer.shopware.com/docs/guides/plugins/plugins/install-activate-plugin.html

Quellcode (relativ zu `vendor/shopware/`): `core/Framework/Plugin.php`; `core/Framework/Plugin/PluginLifecycleService.php` (`installPlugin`, `uninstallPlugin`, `updatePlugin`, `activatePlugin`, `deactivatePlugin`, `runMigrations`, `rebuildContainerWithNewPluginState`); `core/Framework/Plugin/PluginService.php` (`refreshPlugins`); `core/Framework/Plugin/Context/*`; `core/Framework/Plugin/Event/*`; `core/Framework/Plugin/PluginEvents.php`; `core/Framework/Plugin/Command/{PluginRefreshCommand,PluginListCommand}.php` und `Command/Lifecycle/*`; `storefront/Theme/Subscriber/PluginLifecycleSubscriber.php`; `administration/Resources/app/administration/src/module/sw-extension/service/extension-store-action.service.ts`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Es wurden keine Plugins ausgeführt, die Beispiele sind aus dem Code abgeleitet (kein `php -l`, keine `bin/console`-Läufe). Zeilennummern in Abschnitt 2 sind ungefähr.
- ⚠ UNGEPRÜFT: „Plugin-eigene Services sind in `install()` nicht verfügbar" und „eigener Subscriber erhält `PluginPostInstallEvent` nicht zuverlässig" sind aus dem Code abgeleitet, nicht per Test bestätigt.
- ⚠ LÜCKE: Doku nennt fünf Stufen und `postInstall`/`postUpdate` separat; der Code kennt sieben Hooks. Übrige CLI-Befehle/Optionen sind nur im Code belegt.
- ⚠ LÜCKE: Docblock von `UninstallContext::keepUserData()` („migrations will also be removed" bei true) ist irreführend; Code entfernt Migrationen bei `keepUserData()===false`.
- ⚠ UNGEPRÜFT: Unterschiede zu 6.5/6.6 sind nicht belegt (keine alten Quellen lokal). Composer-Logik (`executeComposerCommands`, `cluster_setup`) nur oberflächlich geprüft, siehe [T-02](./02-plugin-aufbau.md).
