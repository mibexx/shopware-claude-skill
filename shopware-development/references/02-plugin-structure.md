# Plugin structure, composer.json and dependencies (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when creating a new plugin: required fields of `composer.json`, plugin class, directory structure, Composer/npm dependencies and dependencies between plugins.

Scope: placement within the project in [T-01 Project structure](./01-project-structure-dev-environment.md); install/update/uninstall hooks in [T-03 Plugin lifecycle](./03-plugin-lifecycle.md); service registration in [T-05](./05-registering-services.md); build details in [T-49](./49-build-deployment.md). Scaffold: [../templates/plugin/README.md](../templates/plugin/README.md).

## 2. Concepts

- A plugin is a Composer package with `"type": "shopware-platform-plugin"` (`PluginFinder::COMPOSER_TYPE`).
- **Required fields according to the code** (`PluginFinder::isShopwarePluginType()`, `isPluginComposerValid()`, `PluginService::refreshPlugins()`):
  1. `type` = `shopware-platform-plugin`
  2. `extra.shopware-plugin-class` (FQCN, not empty)
  3. `extra.label` (not empty, object per locale, e.g. `de-DE`, `en-GB`). The docs do not call it mandatory; the code applies.
  4. `autoload.psr-4` or `psr-0` (otherwise error "Neither a PSR-4 nor PSR-0 autoload information is given.")
  5. The composer.json must pass Composer validation (`PackageProvider`, `ConfigValidator`): errors abort, warnings are printed. The scaffold stub contains `name`, `description`, `version`, `license`, `require.shopware/core`.
- Optional `extra` fields (`PluginService`): `description`, `manufacturerLink`, `supportLink` (each a locale object), `copyright`, `plugin-icon` (default `src/Resources/config/plugin.png`).
- Plugin class: `abstract class Plugin extends Bundle` (`core/Framework/Plugin.php`); Bundle extends the Symfony `Bundle`. The constructor is `final` and `@internal`: do not override it, do not inject anything through it.
- `executeComposerCommands(): bool` (default `false`): if `true`, Shopware runs `composer require <name>:<version>` on install/update and `composer remove` on uninstall.

### Directory tree

```text
custom/plugins/SwagExample/               (or custom/static-plugins/...)
├── composer.json                         # mandatory
├── src/
│   ├── SwagExample.php                   # plugin class (extends Plugin)
│   ├── Resources/
│   │   ├── config/{services.php,routes.php,config.xml,plugin.png}
│   │   ├── views/                        # Twig (storefront/...)
│   │   └── app/{administration,storefront}/   # src/main.ts|js, optional package.json, build/webpack.config.js
│   └── Migration/                        # namespace <PluginNS>\Migration
├── tests/                                # autoload-dev: <NS>\Tests\
└── vendor/                               # only for zip delivery with Composer dependencies
```

From `Bundle`: `build(ContainerBuilder)` (loads `Resources/config/services.*`, registers the migration path, filesystems `private`/`public`), `getMigrationNamespace()`/`getMigrationPath()`, `getTemplatePriority()` (default 0), `getAdditionalBundles()`, `getAdminBaseUrl()`. `getPath()` (Symfony) = directory of the plugin class, usually `src/`.

## 3. Instructions

1. **Create**: `bin/console plugin:create SwagExample Swag` (arguments `plugin-name`, `plugin-namespace`, both PascalCase; without arguments and non-interactive it errors). Options `--static` (-> `custom/static-plugins/<Name>`), `--no-scaffold` (required files only) as well as generator options (Command, Config, Entity, EventSubscriber, ScheduledTask, StoreApiRoute, StorefrontController, AdminModule, JavascriptPlugin, CustomFieldset, Tests; see `Scaffolding/Generator/`). If the directory exists, the command aborts; on errors it is deleted again. The Composer name is built from namespace/name in kebab-case (`ComposerGenerator`).
2. **Check composer.json** (required fields above). The docs recommend the static plugin approach (plugin in the project via Composer).
3. **Discover**: `bin/console plugin:refresh` (`-s` suppresses the list). `PluginFinder` searches `custom/plugins` (depth 0), all Composer packages of type `shopware-platform-plugin` and the root package.
4. **Lifecycle**: `plugin:install [-a|--activate] [--reinstall]`, `plugin:activate`, `plugin:deactivate`, `plugin:uninstall [--keep-user-data]`, `plugin:update`, `plugin:update:all`. Shared options `-r/--refresh`, `-c/--clearCache`, `--skip-asset-build` (details [T-03](./03-plugin-lifecycle.md)).
5. **Build config**: `bin/console bundle:dump` writes `var/plugins.json` (argument `dumpFilePath`); the build scripts read it.
6. **If `build()` is overridden**, always call `parent::build($container)`, otherwise services/migrations are not registered.
7. **Composer dependencies (third-party libraries)**:
   - Composer/static plugin: regular `require` in the plugin composer.json.
   - Zip plugin (Store): ship `vendor/` (docs: max. 5 MB bundle) **or** override `executeComposerCommands(): true`. Code (`PluginLifecycleService::executeComposerRequireWhenNeeded`, `CommandExecutor`): `composer require <name>:<version> --update-with-dependencies --no-scripts` in the project directory; skipped with `shopware.deployment.cluster_setup` or when the installed version (`InstalledVersions`) equals the plugin version. Uninstall -> `composer remove`; afterwards `opcache_reset()` and `refreshPlugins`.
   - Docs tip: temporarily remove `shopware/core` from `require` when running `composer require` in the plugin. Put private packages in `<plugin>/packages/` and reference them in the composer.json (docs).
8. **npm dependencies**: `npm init -y` in `src/Resources/app/administration/` or `.../storefront/` respectively, then `npm install <package>`. `bin/build-storefront.sh` reads `var/plugins.json` and runs `npm install --prefer-offline` per plugin if `package.json` exists and `node_modules` is missing. Administration (6.7, according to the docs): Vite resolves packages from the plugin's own `node_modules`; Storefront: Webpack, if needed `src/Resources/app/storefront/build/webpack.config.js`.
9. **Plugin dependencies** via `require`/`conflict` (example below). `RequirementsValidator::validateRequirements()` runs on install/update/activate (for Composer-managed plugins only on activate) and checks Composer packages, installed/active plugins, `replace` and bundled `vendor` packages; violations are collected by `RequirementExceptionStack`. `resolveActiveDependants()` prevents deactivating/uninstalling a plugin that active plugins depend on. What counts is the Composer package name, not the label or class.

## 4. Examples

`custom/plugins/SwagExample/composer.json`

```json
{
    "name": "swag/example",
    "description": "Example plugin",
    "type": "shopware-platform-plugin",
    "version": "1.0.0",
    "license": "MIT",
    "require": {
        "shopware/core": "~6.7.0"
    },
    "extra": {
        "shopware-plugin-class": "SwagExample\\SwagExample",
        "label": {
            "de-DE": "Beispiel-Plugin",
            "en-GB": "Example plugin"
        },
        "description": {
            "de-DE": "Minimales Beispiel-Plugin",
            "en-GB": "Minimal example plugin"
        }
    },
    "autoload": {
        "psr-4": {
            "SwagExample\\": "src/"
        }
    }
}
```

`src/SwagExample.php`

```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;

class SwagExample extends Plugin
{
}
```

`src/Resources/config/services.php` (optional; `Bundle` loads `Resources/config/services.*`; autowiring is not the default, see [T-05](./05-registering-services.md))

```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();
};
```

Start:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample
```

Let Shopware install Composer packages (zip plugin without `vendor/`):

```php
// src/SwagExample.php
public function executeComposerCommands(): bool
{
    return true;
}
```

Plugin dependencies in `composer.json`:

```json
"require": {
    "shopware/core": "~6.7.0",
    "swag/other-plugin": "^1.0"
},
"conflict": {
    "swag/legacy-plugin": "<2.0"
}
```

## 5. Pitfalls

- `extra.label` missing or `type` wrong -> plugin not in `plugin:list`; message "invalid "type" ..., or missing extra.label property" (`PluginFinder::addError`).
- No `autoload.psr-4`/`psr-0` -> `refreshPlugins` error. For plugins not managed by Composer, `KernelPluginLoader::registerPluginNamespaces` registers namespaces at runtime; paths must be below the project directory.
- `shopware-plugin-class` wrong/class not loadable -> `ComposerPluginLoader` skips the package ("Skipped package ... due invalid "shopware-plugin-class" config"); the `KernelPluginLoader` ignores the class without a message (`class_exists`).
- Plugin class does not extend `Shopware\Core\Framework\Plugin` -> `PluginException::kernelPluginLoaderError`. Overriding the constructor is not possible (`final`).
- composer.json changed but no `plugin:refresh`/cache clear (`-c`) -> changes are not picked up.
- `removeMigrations()` throws for namespaces starting with `Shopware` (except `Shopware\Commercial`).
- Zip plugin with a third-party library but without `vendor/` and without `executeComposerCommands()` -> classes are missing.
- `executeComposerCommands()` has no effect in a cluster setup (`shopware.deployment.cluster_setup`) -> install dependencies beforehand via Composer.
- Anti-pattern: pinning `shopware/core` to a patch version (`6.7.15.1`); better use `~6.7.0` and do not pin plugin dependencies exactly.

### Deprecations and version notes (6.5/6.6 → 6.7)

- The scaffold stub uses `"shopware/core": "~6.7.0"` and generates `services.php`/`routes.php` (PHP) instead of XML. XML services/routes (`services.xml`, `routes.xml`, `routes_overwrite.xml`, package config) are deprecated in 6.7 (`Bundle.php`, `@deprecated tag:v6.8.0`) and will no longer be loaded from 6.8. `config.xml` (plugin configuration) is assumed not to be affected.
- Administration build in 6.7 with Vite (docs), Storefront still Webpack (docs); in the code, the Storefront additionally contains `vite.*.config.mts` and `vitest.config.mts` -> [T-49](./49-build-deployment.md).
- `Plugin::executeComposerCommands()` and `CommandExecutor` are present unchanged in 6.7.

## 6. Checklist

- [ ] `type` = `shopware-platform-plugin`, `extra.shopware-plugin-class` points to a loadable class.
- [ ] `extra.label` set for `de-DE` and `en-GB`.
- [ ] `autoload.psr-4` matches the namespace of the plugin class.
- [ ] `require.shopware/core` as `~6.7.0`, no exact patch pins.
- [ ] Services as `services.php` (PHP), not XML.
- [ ] If `build()` was overridden: `parent::build($container)` called.
- [ ] Zip plugin: `vendor/` included or `executeComposerCommands()` set deliberately.
- [ ] Plugin dependencies via Composer package names in `require`/`conflict`.
- [ ] `plugin:refresh` after every composer.json change.

## 7. Sources

Docs (fetched, summarized):
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-base-guide.html
- https://developer.shopware.com/docs/guides/plugins/plugins/dependencies/add-plugin-dependencies.html
- https://developer.shopware.com/docs/guides/plugins/plugins/dependencies/using-composer-dependencies.html
- https://developer.shopware.com/docs/guides/plugins/plugins/dependencies/using-npm-dependencies.html
- https://developer.shopware.com/docs/guides/plugins/plugins/creating-plugins.html (not fetched, unverified)

Source code (relative to `vendor/shopware/`): `core/Framework/Plugin.php`, `core/Framework/Bundle.php`, `core/Framework/Plugin/Util/PluginFinder.php`, `core/Framework/Plugin/PluginService.php`, `core/Framework/Plugin/Composer/{PackageProvider,CommandExecutor}.php`, `core/Framework/Plugin/PluginLifecycleService.php` (`executeComposerRequireWhenNeeded`), `core/Framework/Plugin/KernelPluginLoader/*`, `core/Framework/Plugin/Requirement/{RequirementsValidator,RequirementExceptionStack}.php`, `core/Framework/Plugin/Command/{PluginCreateCommand,PluginRefreshCommand,BundleDumpCommand}.php`, `core/Framework/Plugin/Command/Scaffolding/` (`stubs/composer.stub`, `stubs/plugin-class.stub`), project: `bin/build-storefront.sh`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: `plugin:create` was not executed; the derived Composer name is a derivation from the code.
- ⚠ UNVERIFIED: Statements about the Store size limit (5 MB) and build commands (`composer build:js:admin`, `shopware-cli`) come from the docs only; `creating-plugins.html` not fetched.
- ⚠ GAP: The docs say `executeComposerCommands` applies on "activation"; the code runs it on install and update (the code applies).
- ⚠ UNVERIFIED: That `config.xml` is not affected by the XML deprecation is an assumption.
- ⚠ UNVERIFIED: The comparison with 6.5/6.6 is based on memory, not on old code; `DbalKernelPluginLoader`/`StaticKernelPluginLoader` only read superficially.
