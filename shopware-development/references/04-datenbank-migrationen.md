# Datenbank-Migrationen (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift bei jeder Schemaänderung (Tabellen, Spalten, Indizes) und bei einmaligen, versionierten Datenänderungen in der Datenbank eines Plugins. Sie erklärt Erzeugen, Ausführen, Expand/Contract und Aufräumen.

Abgrenzung: Hooks `install`/`update`/`uninstall` in [T-03 Plugin-Lifecycle](./03-plugin-lifecycle.md); Entity-Definitionen in [T-27 DAL-Entities](./27-dal-entities.md); Entity-Erweiterungen in [T-29](./29-entity-extensions-custom-fields.md). Gerüst: [../templates/migration/README.md](../templates/migration/README.md).

## 2. Konzepte

- Eine Migration erweitert `Shopware\Core\Framework\Migration\MigrationStep` mit drei Methoden:
  - `getCreationTimestamp(): int` (abstract)
  - `update(Connection $connection): void` (abstract, nicht-destruktiv)
  - `updateDestructive(Connection $connection): void` (optional, leerer Default, destruktiv)
- Datei und Klasse heißen `Migration<UnixTimestamp><Beschreibung>`; der Namens-Timestamp soll dem Rückgabewert von `getCreationTimestamp()` entsprechen (Konvention; im Code nicht erzwungen, die Reihenfolge entscheidet allein der Rückgabewert).
- Plugin-Migrationen liegen in `src/Migration/`, Namespace `<PluginNamespace>\Migration` (`Bundle::getMigrationNamespace()`/`getMigrationPath()`); das Verzeichnis wird automatisch als Migration-Source registriert (Tag `shopware.migration_source`).
- Regeln: idempotent (`IF NOT EXISTS`, Existenzprüfungen); rückwärtskompatibel (Expand/Contract: erst neu anlegen, Altes später in `updateDestructive()` entfernen); eine veröffentlichte Migration nie ändern; es gibt keinen Rollback.
- Hilfsmethoden `MigrationStep`: `addColumn`, `dropColumnIfExists`, `dropForeignKeyIfExists`, `dropIndexIfExists`, `dropTableIfExists`, `indexExists`, `registerIndexer`, `addAdditionalPrivileges`, `isInstallation`. `AddColumnTrait::addColumn($connection, $table, $column, $type, bool $nullable = true, string $default = 'NULL'): bool` gibt `false` zurück, wenn die Spalte existiert (versucht `ALGORITHM=INSTANT`). Zusätzlich `columnExists(Connection, string $table, string $column): bool` (aus `ColumnExistsTrait`, in `MigrationStep` über `AddColumnTrait` eingebunden) und `indexExists(Connection, string $table, string $index): bool`; alle `protected`, Aufruf per `$this->…`. `TableHelper` (`core/Framework/Util/Database/TableHelper.php`) ist `@internal` und `@final`: in Plugins nicht direkt verwenden, nur die `MigrationStep`-Helfer.
- Intern: `MigrationCollection::sync()` trägt Klassen in Tabelle `migration` ein; `MigrationRuntime::migrate()` führt Einträge mit `update IS NULL` nach `creation_timestamp` ASC aus, einzeln, Fehler werden geloggt und weitergeworfen. Keine umschließende Transaktion.

```text
custom/plugins/SwagExample/src/
├── SwagExample.php                       # ggf. uninstall() zum Aufräumen
└── Migration/
    ├── Migration1730000000CreateExampleTable.php
    └── Migration1730000100AddSortOrder.php
```

Core zum Vergleich: `core/Migration/V6_3 ... V6_8/Migration<ts><Name>.php`, Namespace `Shopware\Core\Migration\V6_<minor>`.

## 3. Anleitung

1. Skelett erzeugen: `bin/console database:create-migration -p SwagExample --name CreateExampleTable`
   - Optionen: `-p/--plugin`, `--name` (nur `[a-zA-Z0-9_]`), `--package`, optional `directory` und `namespace` (nur zusammen). Plugin-Name per Präfix gesucht, bei Mehrdeutigkeit gilt der exakte Treffer. `src/Migration` wird bei Bedarf angelegt.
   - Template `MigrationTemplatePlugin.txt` enthält nur `getCreationTimestamp()` und `update()`, kein `updateDestructive()` und leeres `update()`.
2. Alternativ aus Entity-Schema: `bin/console dal:migration:create swag_example --bundle SwagExample` (`@internal`, Optionen `--namespace` nur Core, `--bundle`, `--package`; SQL aus `MigrationQueryGenerator::generateQueries()`). Ergebnis prüfen.
3. `update()` idempotent ausfüllen (Hilfsmethoden oben).
4. Ausführen:
   - Automatisch nur bei `plugin:install` (nach `Plugin::install()`) und `plugin:update` (nach `Plugin::update()`): nur `update()`, **nie** `updateDestructive()`. Bei `plugin:activate` setzt der Core `setAutoMigrate(false)` VOR `activate()`; Migrationen laufen dort nur, wenn das Plugin in `activate()` selbst `$context->setAutoMigrate(true)` aufruft (`runMigrations()` folgt direkt nach `activate()`; `PluginLifecycleService::activatePlugin`). Bei Deactivate und Uninstall ist `autoMigrate` ebenfalls false.
   - Manuell: `bin/console database:migrate SwagExample --all`; Optionen `--all`, `--until <ts>`, `--limit <n>`. Ohne `--all`/`--until` bricht der Befehl ab; mehrere Identifier nur mit `--all` und ohne `--limit`.
   - Destruktiv, nur manuell: `bin/console database:migrate-destructive SwagExample --all`.
   - Core: `database:migrate core --all`, `database:migrate-destructive core --all --version-selection-mode=safe|blue-green|all`. `system:install` führt beides aus; `system:update:finish` ebenfalls (`--skip-migrations` lässt aus, `--version-selection-mode` Default `safe`). Die Befehle leeren am Ende den Cache.
5. Automatik abschalten: `InstallContext::setAutoMigrate(false)`; dann selbst `$context->getMigrationCollection()->migrateInPlace()` bzw. `migrateDestructiveInPlace()`. `PluginLifecycleService` setzt es bei Activate (vor `activate()`), Deactivate und Uninstall selbst auf false; bei Activate führt nur `setAutoMigrate(true)` in `activate()` zu einem Migrationslauf, bei Deactivate/Uninstall gibt es keinen `runMigrations()`-Aufruf.
6. Beim Uninstall ohne `keepUserData` löscht `Plugin::removeMigrations()` die Einträge aus `migration` (`DELETE ... WHERE class LIKE <Namespace>%`); eine Neuinstallation führt sie erneut aus. Tabellen räumt das Plugin selbst in `uninstall()` auf.

## 4. Beispiele

`src/Migration/Migration1730000000CreateExampleTable.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1730000000CreateExampleTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_example` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);
    }
}
```

`src/Migration/Migration1730000100AddSortOrder.php` (Expand-Schritt mit `addColumn`, Contract-Schritt in `updateDestructive`)

```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1730000100AddSortOrder extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000100;
    }

    public function update(Connection $connection): void
    {
        $this->addColumn($connection, 'swag_example', 'sort_order', 'INT(11)');
    }

    public function updateDestructive(Connection $connection): void
    {
        // erst in einer späteren Version, wenn kein Code mehr die alte Spalte nutzt
        $this->dropColumnIfExists($connection, 'swag_example', 'old_column');
    }
}
```

Aufräumen in `src/SwagExample.php` (Migrationen werden nicht zurückgerollt):

```php
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

public function uninstall(UninstallContext $uninstallContext): void
{
    if ($uninstallContext->keepUserData()) {
        return;
    }

    $this->container->get(Connection::class)->executeStatement('DROP TABLE IF EXISTS `swag_example`');
}
```

Installation und manuelle Ausführung:

```bash
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
bin/console database:migrate SwagExample --all
bin/console database:migrate-destructive SwagExample --all
```

## 5. Fallstricke

- Ausgelieferte Migration nachträglich ändern: läuft nicht erneut; stattdessen neue Migration schreiben.
- Nicht idempotent: `CREATE TABLE` ohne `IF NOT EXISTS`, `ALTER TABLE ADD COLUMN` ohne Prüfung. `$this->addColumn()`, `$this->columnExists()`, `$this->indexExists()` und die `drop*IfExists`-Helfer von `MigrationStep` nutzen (nicht `TableHelper`, `@internal`).
- Destruktives (`DROP COLUMN`, Rename) in `update()`: bricht alte Codeversionen und Blue-Green-Deployments. Erst anlegen, Daten kopieren, Altes in `updateDestructive()`.
- `plugin:install`/`plugin:update` führen `updateDestructive()` **nicht** aus (nur `DatabaseMigrator`, `SystemInstallCommand`, `MigrationDestructiveCommand` im Core).
- Namens-Timestamp und `getCreationTimestamp()` weichen ab: verwirrende Reihenfolge. Werte `<1` oder `>=2147483647` werfen `MigrationException::implausibleCreationTimestamp`.
- Falscher Namespace/Autoload: `MigrationCollection::loadMigrationSteps` wirft `MigrationException::invalidMigrationClass`, wenn eine `.php`-Datei im Migrationsordner keine ladbare Klasse enthält. Hilfsklassen daher nicht in `src/Migration/` ablegen.
- Erwarten, dass `plugin:uninstall` Tabellen entfernt: `uninstall()` selbst, mit `keepUserData()`-Prüfung.
- Lange Migrationen: Guideline empfiehlt unter 10 Sekunden; der Web-Updater `DatabaseMigrator` hat einen 7-Sekunden-Request-Cap und arbeitet schrittweise.
- Auf konkrete IDs oder Daten verlassen oder Kundenanpassungen überschreiben (Guideline Rules 3-5).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `MigrationStep`-Aufbau und Namenskonvention unverändert. `MigrationStep::indexExists()` trägt `#[ExceptionChange(version: 'v6.8.0')]`: mit Feature-Flag `v6.8.0.0` delegiert der Core intern an `TableHelper::indexExists` (gleiches gilt für `columnExists`) und es gibt `false` statt `TableNotFoundException`. Plugins rufen weiterhin `$this->indexExists()` bzw. `$this->columnExists()` auf; `TableHelper` ist `@internal`/`@final`.
- Zusätzliche Helfer in 6.7.15.1: `AddColumnTrait::addColumn()`, `NonStandardFkGuard::executeDdl()` (`@internal`), `TableHelper` (`@internal`, nur Core-intern).
- Generator wählt `V6_<minor>` aus der Shopware-Version (`explode('.')[1]`, hier 7); `V6_8` existiert als Ordner.
- `--version-selection-mode`: `safe` = Major minus 2 (Default), `blue-green` = Major minus 1, `all` = aktuell (`MigrationCollectionLoader::getLastSafeMajorVersion`).
- Doku/Code: Das Scaffold-Stub (`migration.stub` bei `plugin:create`) nutzt `CREATE TABLE IF NOT EXISTS`; das Template von `database:create-migration -p` liefert nur ein leeres `update()`.

## 6. Checkliste

- [ ] Klasse `Migration<ts><Name>` erweitert `MigrationStep`, Namens-Timestamp = `getCreationTimestamp()`.
- [ ] Namespace `<PluginNamespace>\Migration`, Datei liegt in `src/Migration/`.
- [ ] `update()` idempotent (`IF NOT EXISTS`, `addColumn`, `*IfExists`).
- [ ] Destruktive Änderungen nur in `updateDestructive()` (Expand/Contract).
- [ ] Keine bereits veröffentlichte Migration geändert.
- [ ] Migration läuft unter 10 s, keine hartkodierten IDs.
- [ ] `uninstall()` löscht Tabellen nur bei `!keepUserData()`.
- [ ] Mit `plugin:install`/`database:migrate SwagExample --all` getestet.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/database/database-migrations.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/database-migations.html (URL-Schreibweise „migations" ist tatsächlich so)

Quellcode (relativ zu `vendor/shopware/`): `core/Framework/Migration/{MigrationStep,AddColumnTrait,ColumnExistsTrait,MigrationCollection,MigrationRuntime,MigrationCollectionLoader,MigrationSource,MigrationException}.php`; `core/Framework/Migration/Command/{CreateMigrationCommand,MigrationCommand,MigrationDestructiveCommand,RefreshMigrationCommand}.php`; `core/Framework/Migration/Template/*.txt`; `core/Framework/DataAbstractionLayer/Command/CreateMigrationCommand.php` und `MigrationQueryGenerator.php`; `core/Framework/Bundle.php`; `core/Framework/Plugin.php` (`removeMigrations`); `core/Framework/Plugin/PluginLifecycleService.php` (`runMigrations`); `core/Framework/Plugin/Context/InstallContext.php`; `core/Maintenance/System/Command/{SystemInstallCommand,SystemUpdateFinishCommand}.php`; `core/Installer/Database/DatabaseMigrator.php`; `core/Framework/Util/Database/TableHelper.php`; `core/Migration/V6_7/Migration1788964043AddRefreshTokenFamily.php` (Beispiel); `core/Framework/Plugin/Command/Scaffolding/stubs/migration.stub`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Kein `bin/console` ausgeführt; alle CLI-Aussagen stammen aus dem Quellcode. Signatur von `addColumn` am Code geprüft.
- ⚠ UNGEPRÜFT: Ob `update()` in einer Transaktion läuft, ist nicht belegt (keine Transaktion in `MigrationRuntime::migrate()` sichtbar; DDL committet in MySQL implizit).
- ⚠ UNGEPRÜFT: Ausgabeformat von `dal:migration:create` (`MigrationFileRenderer`) nicht im Detail gelesen; Nachbearbeitung ist Annahme.
- ⚠ LÜCKE: Einführungsversion der Helfer (`addColumn`, `columnExists`) nicht verifiziert; Verhalten von `plugin:update` ohne neue Migration nicht gesondert geprüft.
- ⚠ UNGEPRÜFT: Doku wurde über Zusammenfassungs-Fetch gelesen; die Guideline-Zählung („9 Rules", 10-Sekunden-Grenze) nur als Orientierung. Belegt: Idempotenz, nie ändern, Expand/Contract.
- Belegt (`core/Framework/Plugin/PluginLifecycleService.php`): `setAutoMigrate(false)` in `uninstallPlugin` (Z. 218), `activatePlugin` (Z. 394, danach `activate()` und `runMigrations()` Z. 398) und `deactivatePlugin` (Z. 469; dort kein `runMigrations()`).
