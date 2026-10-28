# Plugin lifecycle (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin needs to run code or clean up on installation, update, activation, deactivation or uninstallation, and when you need the related CLI commands and events. The authoritative source is `PluginLifecycleService` (actual state in 6.7.15.1).

Scope: required fields and Composer in [T-02 Plugin structure](./02-plugin-structure.md); schema changes in [T-04 Migrations](./04-database-migrations.md); event subscribers in general in [T-07](./07-events-subscriber.md); service registration in [T-05](./05-registering-services.md).

## 2. Concepts

- `Shopware\Core\Framework\Plugin` provides seven empty, overridable hooks, each with a context: `install`, `postInstall`, `update`, `postUpdate`, `activate`, `deactivate`, `uninstall`. They are called only by the `PluginLifecycleService` (`@internal`), which is used by the CLI, the Administration (extension manager, `_action/extension/...`) and the Store download.
- Context hierarchy (`core/Framework/Plugin/Context/`): `InstallContext` is the base; `UpdateContext`, `UninstallContext`, `ActivateContext`, `DeactivateContext` extend it.

| Method | Class | Meaning |
|---|---|---|
| `getPlugin()` | `InstallContext` | plugin base class (not the entity) |
| `getContext()` | `InstallContext` | Shopware `Context` (CLI: `Context::createCLIContext()`, SystemSource) |
| `getCurrentShopwareVersion()` | `InstallContext` | Shopware version |
| `getCurrentPluginVersion()` | `InstallContext` | on update, the previously installed version |
| `getMigrationCollection()` | `InstallContext` | the plugin's `MigrationCollection` |
| `isAutoMigrate()` / `setAutoMigrate(bool)` | `InstallContext` | install/update default true (for activate/deactivate/uninstall the core sets it to false); set it to false and control `getMigrationCollection()->migrateInPlace()` yourself |
| `getUpdatePluginVersion()` | `UpdateContext` | target version |
| `keepUserData()` | `UninstallContext` | true = "keep data" (Admin: `keepUserData: !removeData`, CLI: `--keep-user-data`) |

`ActivateContext` and `DeactivateContext` are empty subclasses.

### Directory tree

```text
custom/plugins/SwagExample/src/
├── SwagExample.php                    # hooks
├── Migration/Migration1730000000CreateExampleTable.php   # run on install/update
├── Subscriber/PluginLifecycleSubscriber.php              # optional: Plugin*Event
└── Resources/config/{services.php,config.xml,custom-fields.xml}
```

`config.xml` is stored as the system config default on install/update; `custom-fields.xml` is synchronized and removed on uninstall (without `keepUserData`).

### Flow per phase (`PluginLifecycleService`, line numbers for 6.7.15.1)

**installPlugin** (lines 114-190): (1) `installedAt` already set -> no-op. (2) Check requirements (`RequirementsValidator`) or, with `executeComposerCommands()`, `composer require`. (3) `PluginPreInstallEvent`. (4) `SystemConfigService::savePluginConfiguration($plugin, true)`. (5) `install()`. (6) Migrations (only with `isAutoMigrate()`). (7) Custom fields. (8) `installedAt` in DB. (9) `postInstall()`. (10) `PluginPostInstallEvent`. Errors: if needed `composer remove`; if `installedAt` is already set (error in `postInstall`/post event), `uninstallPlugin(keepUserData: true)` follows. Errors in `install()` or migrations do **not** lead to a DB rollback.

**activatePlugin** (lines 352-436): not installed -> exception; already active -> no-op. Check requirements, `PluginPreActivateEvent`, kernel reboot with the plugin active (`rebuildContainerWithNewPluginState`; in CLI only if `rebuildContainer()===true`, default true; when called from the Administration always), new `ActivateContext` with `setAutoMigrate(false)`, `activate()` on the new instance, `runMigrations()` (runs only if `activate()` itself set `setAutoMigrate(true)`), copy assets, `active=true`, `PluginPostActivateEvent` (if a listener throws, `active` is reset).

**deactivatePlugin** (lines 438-526): not installed/not active -> exception; active dependent plugins block it. `PluginPreDeactivateEvent`, `deactivate()`, remove assets, `active=false`, kernel reboot, DB update. On exception `PluginPostDeactivationFailedEvent`, on success `PluginPostDeactivateEvent`.

**updatePlugin** (lines 267-350): must be installed; `getUpdatePluginVersion()` = `upgradeVersion ?? version`. `PluginPreUpdateEvent`, save system config (do not overwrite existing values), `update()`; if `update()` throws, an active plugin is deactivated and the exception is rethrown. Then assets (only if active, without skip state), migrations, custom fields (`deleteMissingXml=true`: without XML, all of the plugin's sets are removed), DB version, `postUpdate()`, `PluginPostUpdateEvent`.

**uninstallPlugin** (lines 194-262): an active plugin is deactivated first (incl. hooks/events). `PluginPreUninstallEvent`, remove assets. Without `keepUserData`: `Plugin::removeMigrations()` deletes rows from `migration` (BEFORE `uninstall()`, so migrations run again on failure). `uninstall()` (context with `autoMigrate=false`). Without `keepUserData`: delete the plugin's system config. DB: `active=false`, `installedAt=null`. Without `keepUserData`: remove custom entities and custom fields. With `executeComposerCommands()`: `composer remove`. `PluginPostUninstallEvent`.

The core does **not** clean up: plugin tables and columns as well as created payment methods, rules, mail templates. `uninstall()` must do that itself (docs: do not blindly delete entities, e.g. a payment method used in historical orders; better deactivate it).

### Events (`core/Framework/Plugin/Event/`, all extend `PluginLifecycleEvent`, `getPlugin(): PluginEntity`)

| Event | When | `getContext()` |
|---|---|---|
| `PluginPreInstallEvent` / `PluginPostInstallEvent` | before `install()` / after `postInstall()` | `InstallContext` |
| `PluginPreUpdateEvent` / `PluginPostUpdateEvent` | before `update()` / after `postUpdate()` | `UpdateContext` |
| `PluginPreActivateEvent` / `PluginPostActivateEvent` | before reboot and `activate()` / after `active=true` | `ActivateContext` |
| `PluginPreDeactivateEvent` / `PluginPostDeactivateEvent` | before `deactivate()` / after deactivation | `DeactivateContext` |
| `PluginPostDeactivationFailedEvent` | exception during deactivation (`getException(): ?\Throwable`) | `ActivateContext` |
| `PluginPreUninstallEvent` / `PluginPostUninstallEvent` | before asset removal / after DB update and Composer remove | `UninstallContext` |

Subscribe via FQCN. `PluginEvents` contains only DAL events of the plugin entity (`plugin.written`, `plugin.loaded` ...), no lifecycle events. Core subscribers on them: `storefront/Theme/Subscriber/PluginLifecycleSubscriber.php` (theme compilation), `core/Framework/MessageQueue/Subscriber/PluginLifecycleSubscriber.php` (scheduled tasks), `RegisteredIndexerSubscriber` (indexers).

## 3. Instructions

1. Create and discover: `bin/console plugin:create SwagExample Swag`, then `bin/console plugin:refresh` (`-s` suppresses the list). For already installed plugins with a higher version on disk, `upgradeVersion` is set (DB `version` stays); for non-installed ones, `version` is overwritten.
2. `bin/console plugin:list` shows version, upgrade version, installed/active.
3. Shared options (`AbstractPluginLifecycleCommand`): argument `plugins` (multiple; exact name match, otherwise `ContainsFilter`), `-r/--refresh` (runs `plugin:refresh -s` first), `-c/--clearCache`, `--skip-asset-build` (context state `skip-asset-building`: skips assets and theme compilation).

| Command | Specifics |
|---|---|
| `plugin:install <p>` | `-a/--activate` activates directly. `--reinstall` first uninstalls **without** `--keep-user-data` (data gone!). `-a` together with `-r`: activation skipped ("Can not refresh and activate in same request"). Already installed ones are skipped (activated with `-a`). Dependencies via `sortPluginsByRequirements`. |
| `plugin:activate <p>` | `PluginNotInstalledException` if not installed |
| `plugin:deactivate <p>` | throws if not active or active dependants exist (`PluginException::hasActiveDependants`) |
| `plugin:update <p>` | updates to `upgradeVersion` |
| `plugin:update:all` | `refreshPlugins`, then only plugins with `upgradeVersion !== null` **and active**; only option `--skip-asset-build` |
| `plugin:uninstall <p>` | `--keep-user-data`; an active plugin is deactivated first |

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console plugin:update SwagExample -c        # after a version bump in composer.json + refresh
bin/console plugin:deactivate SwagExample
bin/console plugin:uninstall SwagExample --keep-user-data
bin/console plugin:uninstall SwagExample        # deletes data
```

4. Update flow: bump the version in `composer.json`, `plugin:refresh` (creates `upgradeVersion`), `plugin:update`.
5. Cleanup rules: schema changes always via migration; `update()` only for one-off data adjustments. Start `uninstall()` with `if ($uninstallContext->keepUserData()) { return; }`. Only then delete added columns/tables; with foreign keys to core tables, drop the constraints first. Set payment methods and the like to inactive in `deactivate()` and back to active in `activate()`. Clear the cache after `plugin:*` with `-c`.

## 4. Examples

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
        // Runs BEFORE the migrations; plugin services are not available here.
    }

    public function postInstall(InstallContext $installContext): void
    {
        // After migrations and after installedAt has been set.
    }

    public function update(UpdateContext $updateContext): void
    {
        // Runs BEFORE the update's migrations. Schema changes belong in migrations.
        if (version_compare($updateContext->getCurrentPluginVersion(), '1.1.0', '<')) {
            // one-off data adjustment when updating from < 1.1.0
        }
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Plugin services available (kernel was rebooted).
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return; // keep tables and data
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `swag_example`');
    }
}
```

`src/Migration/Migration1730000000CreateExampleTable.php` (details in [T-04](./04-database-migrations.md); scaffold [../templates/migration/README.md](../templates/migration/README.md))

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

Optional subscriber `src/Subscriber/PluginLifecycleSubscriber.php`:

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
        $event->getPlugin()->getName(); // PluginEntity; $event->getContext() is an ActivateContext
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

The plugin's own subscriber exists in the container only when the plugin is active; events of the plugin itself before activation are not reliable.

## 5. Pitfalls

1. Using your own services in `install()`: the plugin is not active yet, the container does not contain its services. Only core services via `$this->container->get(...)`.
2. Schema in `install()`/`update()` via SQL instead of a migration (repeated execution, no traceability).
3. Ignoring `keepUserData()` and deleting everything (data loss, broken orders). `uninstall()` **always** runs, even with `keepUserData`.
4. Non-idempotent lifecycle code/migrations: no automatic rollback; a retry must work (`IF NOT EXISTS`, `INSERT IGNORE`).
5. `plugin:update:all` updates only **active** plugins.
6. Version bumped but `plugin:refresh` forgotten -> no `upgradeVersion`.
7. Expecting `plugin:install --reinstall` to preserve data: it uninstalls without `keepUserData`.
8. Expecting migrations on `activate()`: there `autoMigrate` is false by default (the core sets it before `activate()`); they only run with `setAutoMigrate(true)` in `activate()`.
9. Long operations in `activate()`/`deactivate()`: the kernel reboot takes time. `rebuildContainer()` may only return `false` if the plugin does not need its services on activation (only takes effect in the CLI).
10. Overriding the constructor/`isActive()` of the plugin class: both are `final`.
11. Using `PluginLifecycleService` directly: `@internal`, no API stability.
12. Context source: in the Administration, `activate` runs with an admin source and forces the reboot; in the CLI with `SystemSource`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Nothing on hooks, contexts and events is `@deprecated` in 6.7.15.1 (checked via grep).
- Deprecated with removal in 6.8.0: `plugin:list --json` (use `--format json` instead), `AbstractPluginLifecycleCommand::handleClearCacheOption()` (replacement `handleClearCache()`).
- Lifecycle events are subscribed via FQCN; there are no string constants in `PluginEvents`.

## 6. Checklist

- [ ] `uninstall()` checks `keepUserData()` first.
- [ ] Schema changes only in migrations, idempotent.
- [ ] `update()` contains only one-off data adjustments, version-dependent via `getCurrentPluginVersion()`.
- [ ] No plugin services used in `install()`.
- [ ] Payment methods/rules/mail templates are deactivated instead of deleted.
- [ ] After a version bump, `plugin:refresh` then `plugin:update`.
- [ ] Added columns in core tables and foreign keys removed in `uninstall()`.
- [ ] Cache cleared after lifecycle commands (`-c`).
- [ ] Lifecycle events subscribed via FQCN, subscriber registered in `services.php`.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/plugin-lifecycle.html
- https://developer.shopware.com/docs/guides/plugins/plugins/install-activate-plugin.html

Source code (relative to `vendor/shopware/`): `core/Framework/Plugin.php`; `core/Framework/Plugin/PluginLifecycleService.php` (`installPlugin`, `uninstallPlugin`, `updatePlugin`, `activatePlugin`, `deactivatePlugin`, `runMigrations`, `rebuildContainerWithNewPluginState`); `core/Framework/Plugin/PluginService.php` (`refreshPlugins`); `core/Framework/Plugin/Context/*`; `core/Framework/Plugin/Event/*`; `core/Framework/Plugin/PluginEvents.php`; `core/Framework/Plugin/Command/{PluginRefreshCommand,PluginListCommand}.php` and `Command/Lifecycle/*`; `storefront/Theme/Subscriber/PluginLifecycleSubscriber.php`; `administration/Resources/app/administration/src/module/sw-extension/service/extension-store-action.service.ts`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: No plugins were executed; the examples are derived from the code (no `php -l`, no `bin/console` runs). Line numbers in section 2 are approximate.
- ⚠ UNVERIFIED: "The plugin's own services are not available in `install()`" and "your own subscriber does not reliably receive `PluginPostInstallEvent`" are derived from the code, not confirmed by a test.
- ⚠ GAP: The docs name five stages and `postInstall`/`postUpdate` separately; the code has seven hooks. The remaining CLI commands/options are only documented in the code.
- ⚠ GAP: The docblock of `UninstallContext::keepUserData()` ("migrations will also be removed" when true) is misleading; the code removes migrations when `keepUserData()===false`.
- ⚠ UNVERIFIED: Differences from 6.5/6.6 are not established (no old sources available locally). Composer logic (`executeComposerCommands`, `cluster_setup`) only checked superficially, see [T-02](./02-plugin-structure.md).
