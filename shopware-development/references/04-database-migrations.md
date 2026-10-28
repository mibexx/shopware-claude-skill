# Database migrations (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies to every schema change (tables, columns, indexes) and to one-off, versioned data changes in a plugin's database. It explains generating, running, expand/contract and cleanup.

Scope: hooks `install`/`update`/`uninstall` in [T-03 Plugin lifecycle](./03-plugin-lifecycle.md); entity definitions in [T-27 DAL entities](./27-dal-entities.md); entity extensions in [T-29](./29-entity-extensions-custom-fields.md). Scaffold: [../templates/migration/README.md](../templates/migration/README.md).

## 2. Concepts

- A migration extends `Shopware\Core\Framework\Migration\MigrationStep` with three methods:
  - `getCreationTimestamp(): int` (abstract)
  - `update(Connection $connection): void` (abstract, non-destructive)
  - `updateDestructive(Connection $connection): void` (optional, empty default, destructive)
- File and class are named `Migration<UnixTimestamp><Description>`; the timestamp in the name should match the return value of `getCreationTimestamp()` (convention; not enforced in the code, the order is determined solely by the return value).
- Plugin migrations live in `src/Migration/`, namespace `<PluginNamespace>\Migration` (`Bundle::getMigrationNamespace()`/`getMigrationPath()`); the directory is registered automatically as a migration source (tag `shopware.migration_source`).
- Rules: idempotent (`IF NOT EXISTS`, existence checks); backward compatible (expand/contract: create the new thing first, remove the old later in `updateDestructive()`); never change a published migration; there is no rollback.
- Helper methods of `MigrationStep`: `addColumn`, `dropColumnIfExists`, `dropForeignKeyIfExists`, `dropIndexIfExists`, `dropTableIfExists`, `indexExists`, `registerIndexer`, `addAdditionalPrivileges`, `isInstallation`. `AddColumnTrait::addColumn($connection, $table, $column, $type, bool $nullable = true, string $default = 'NULL'): bool` returns `false` if the column exists (tries `ALGORITHM=INSTANT`). Additionally `columnExists(Connection, string $table, string $column): bool` (from `ColumnExistsTrait`, included in `MigrationStep` via `AddColumnTrait`) and `indexExists(Connection, string $table, string $index): bool`; all `protected`, called via `$this->…`. `TableHelper` (`core/Framework/Util/Database/TableHelper.php`) is `@internal` and `@final`: do not use it directly in plugins, only the `MigrationStep` helpers.
- Internals: `MigrationCollection::sync()` registers classes in the `migration` table; `MigrationRuntime::migrate()` runs entries with `update IS NULL` ordered by `creation_timestamp` ASC, one by one; errors are logged and rethrown. No enclosing transaction.

```text
custom/plugins/SwagExample/src/
├── SwagExample.php                       # if needed uninstall() for cleanup
└── Migration/
    ├── Migration1730000000CreateExampleTable.php
    └── Migration1730000100AddSortOrder.php
```

Core for comparison: `core/Migration/V6_3 ... V6_8/Migration<ts><Name>.php`, namespace `Shopware\Core\Migration\V6_<minor>`.

## 3. Instructions

1. Generate the skeleton: `bin/console database:create-migration -p SwagExample --name CreateExampleTable`
   - Options: `-p/--plugin`, `--name` (only `[a-zA-Z0-9_]`), `--package`, optional `directory` and `namespace` (only together). The plugin name is matched by prefix; if ambiguous, the exact match applies. `src/Migration` is created if needed.
   - The template `MigrationTemplatePlugin.txt` contains only `getCreationTimestamp()` and `update()`, no `updateDestructive()`, and an empty `update()`.
2. Alternatively from the entity schema: `bin/console dal:migration:create swag_example --bundle SwagExample` (`@internal`, options `--namespace` core only, `--bundle`, `--package`; SQL from `MigrationQueryGenerator::generateQueries()`). Review the result.
3. Fill in `update()` idempotently (helper methods above).
4. Run:
   - Automatically only on `plugin:install` (after `Plugin::install()`) and `plugin:update` (after `Plugin::update()`): only `update()`, **never** `updateDestructive()`. On `plugin:activate` the core sets `setAutoMigrate(false)` BEFORE `activate()`; migrations only run there if the plugin itself calls `$context->setAutoMigrate(true)` in `activate()` (`runMigrations()` follows directly after `activate()`; `PluginLifecycleService::activatePlugin`). On deactivate and uninstall, `autoMigrate` is false as well.
   - Manually: `bin/console database:migrate SwagExample --all`; options `--all`, `--until <ts>`, `--limit <n>`. Without `--all`/`--until` the command aborts; multiple identifiers only with `--all` and without `--limit`.
   - Destructive, manual only: `bin/console database:migrate-destructive SwagExample --all`.
   - Core: `database:migrate core --all`, `database:migrate-destructive core --all --version-selection-mode=safe|blue-green|all`. `system:install` runs both; so does `system:update:finish` (`--skip-migrations` skips them, `--version-selection-mode` default `safe`). The commands clear the cache at the end.
5. Disable automatic migration: `InstallContext::setAutoMigrate(false)`; then call `$context->getMigrationCollection()->migrateInPlace()` or `migrateDestructiveInPlace()` yourself. `PluginLifecycleService` itself sets it to false on activate (before `activate()`), deactivate and uninstall; on activate only `setAutoMigrate(true)` in `activate()` leads to a migration run; on deactivate/uninstall there is no `runMigrations()` call.
6. On uninstall without `keepUserData`, `Plugin::removeMigrations()` deletes the entries from `migration` (`DELETE ... WHERE class LIKE <Namespace>%`); a reinstall runs them again. The plugin cleans up tables itself in `uninstall()`.

## 4. Examples

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

`src/Migration/Migration1730000100AddSortOrder.php` (expand step with `addColumn`, contract step in `updateDestructive`)

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
        // only in a later version, once no code uses the old column anymore
        $this->dropColumnIfExists($connection, 'swag_example', 'old_column');
    }
}
```

Cleanup in `src/SwagExample.php` (migrations are not rolled back):

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

Installation and manual execution:

```bash
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
bin/console database:migrate SwagExample --all
bin/console database:migrate-destructive SwagExample --all
```

## 5. Pitfalls

- Changing a shipped migration afterwards: it does not run again; write a new migration instead.
- Not idempotent: `CREATE TABLE` without `IF NOT EXISTS`, `ALTER TABLE ADD COLUMN` without a check. Use `$this->addColumn()`, `$this->columnExists()`, `$this->indexExists()` and the `drop*IfExists` helpers of `MigrationStep` (not `TableHelper`, `@internal`).
- Destructive operations (`DROP COLUMN`, rename) in `update()`: break old code versions and blue-green deployments. Create first, copy data, remove the old in `updateDestructive()`.
- `plugin:install`/`plugin:update` do **not** run `updateDestructive()` (only `DatabaseMigrator`, `SystemInstallCommand`, `MigrationDestructiveCommand` in the core).
- Name timestamp and `getCreationTimestamp()` differ: confusing order. Values `<1` or `>=2147483647` throw `MigrationException::implausibleCreationTimestamp`.
- Wrong namespace/autoload: `MigrationCollection::loadMigrationSteps` throws `MigrationException::invalidMigrationClass` if a `.php` file in the migration folder does not contain a loadable class. So do not put helper classes in `src/Migration/`.
- Expecting `plugin:uninstall` to remove tables: do it in `uninstall()` yourself, with the `keepUserData()` check.
- Long migrations: the guideline recommends under 10 seconds; the web updater `DatabaseMigrator` has a 7-second request cap and works incrementally.
- Relying on specific IDs or data, or overwriting customer customizations (guideline rules 3-5).

### Deprecations and version notes (6.5/6.6 → 6.7)

- `MigrationStep` structure and naming convention unchanged. `MigrationStep::indexExists()` carries `#[ExceptionChange(version: 'v6.8.0')]`: with feature flag `v6.8.0.0` the core internally delegates to `TableHelper::indexExists` (the same applies to `columnExists`) and returns `false` instead of `TableNotFoundException`. Plugins keep calling `$this->indexExists()` or `$this->columnExists()`; `TableHelper` is `@internal`/`@final`.
- Additional helpers in 6.7.15.1: `AddColumnTrait::addColumn()`, `NonStandardFkGuard::executeDdl()` (`@internal`), `TableHelper` (`@internal`, core-internal only).
- The generator picks `V6_<minor>` from the Shopware version (`explode('.')[1]`, here 7); `V6_8` exists as a folder.
- `--version-selection-mode`: `safe` = major minus 2 (default), `blue-green` = major minus 1, `all` = current (`MigrationCollectionLoader::getLastSafeMajorVersion`).
- Docs/code: the scaffold stub (`migration.stub` on `plugin:create`) uses `CREATE TABLE IF NOT EXISTS`; the template of `database:create-migration -p` only provides an empty `update()`.

## 6. Checklist

- [ ] Class `Migration<ts><Name>` extends `MigrationStep`, name timestamp = `getCreationTimestamp()`.
- [ ] Namespace `<PluginNamespace>\Migration`, file lives in `src/Migration/`.
- [ ] `update()` idempotent (`IF NOT EXISTS`, `addColumn`, `*IfExists`).
- [ ] Destructive changes only in `updateDestructive()` (expand/contract).
- [ ] No already published migration changed.
- [ ] Migration runs in under 10 s, no hardcoded IDs.
- [ ] `uninstall()` deletes tables only when `!keepUserData()`.
- [ ] Tested with `plugin:install`/`database:migrate SwagExample --all`.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/database/database-migrations.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/database-migations.html (the URL spelling "migations" really is like that)

Source code (relative to `vendor/shopware/`): `core/Framework/Migration/{MigrationStep,AddColumnTrait,ColumnExistsTrait,MigrationCollection,MigrationRuntime,MigrationCollectionLoader,MigrationSource,MigrationException}.php`; `core/Framework/Migration/Command/{CreateMigrationCommand,MigrationCommand,MigrationDestructiveCommand,RefreshMigrationCommand}.php`; `core/Framework/Migration/Template/*.txt`; `core/Framework/DataAbstractionLayer/Command/CreateMigrationCommand.php` and `MigrationQueryGenerator.php`; `core/Framework/Bundle.php`; `core/Framework/Plugin.php` (`removeMigrations`); `core/Framework/Plugin/PluginLifecycleService.php` (`runMigrations`); `core/Framework/Plugin/Context/InstallContext.php`; `core/Maintenance/System/Command/{SystemInstallCommand,SystemUpdateFinishCommand}.php`; `core/Installer/Database/DatabaseMigrator.php`; `core/Framework/Util/Database/TableHelper.php`; `core/Migration/V6_7/Migration1788964043AddRefreshTokenFamily.php` (example); `core/Framework/Plugin/Command/Scaffolding/stubs/migration.stub`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: No `bin/console` executed; all CLI statements come from the source code. Signature of `addColumn` verified against the code.
- ⚠ UNVERIFIED: Whether `update()` runs in a transaction is not established (no transaction visible in `MigrationRuntime::migrate()`; DDL commits implicitly in MySQL).
- ⚠ UNVERIFIED: Output format of `dal:migration:create` (`MigrationFileRenderer`) not read in detail; the need for post-editing is an assumption.
- ⚠ GAP: Version in which the helpers (`addColumn`, `columnExists`) were introduced not verified; behavior of `plugin:update` without a new migration not checked separately.
- ⚠ UNVERIFIED: The docs were read via a summarizing fetch; the guideline count ("9 Rules", 10-second limit) is for orientation only. Established: idempotency, never change, expand/contract.
- Established (`core/Framework/Plugin/PluginLifecycleService.php`): `setAutoMigrate(false)` in `uninstallPlugin` (line 218), `activatePlugin` (line 394, followed by `activate()` and `runMigrations()` line 398) and `deactivatePlugin` (line 469; no `runMigrations()` there).
