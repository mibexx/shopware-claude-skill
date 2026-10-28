# Template: plugin (root template)

Shopware 6.7.15.1. Scaffold of a plugin: `composer.json`, plugin class with all lifecycle methods, service registration via `services.php` (standard) and `services.xml.dist` as a deprecated alternative. All other template directories (`config`, `migration`, `entity`, `event-subscriber`, `decorator`, ...) contain only their own files in the same structure (`SwagExample/src/...`) and are copied into this scaffold.

## File tree

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                      # plugin class (extends Shopware\Core\Framework\Plugin)
    └── Resources/
        └── config/
            ├── services.php                 # services (standard in 6.7)
            └── services.xml.dist            # alternative (deprecated), see below
```

Other common locations (filled by other templates): `src/Migration/` (namespace `SwagExample\Migration`, registered automatically), `src/Resources/config/config.xml`, `src/Resources/app/administration|storefront/`, `src/Resources/views/`, `tests/` (autoload-dev `SwagExample\Tests\`). The plugin icon lives by default at `src/Resources/config/plugin.png` (binary file, not part of this template).

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `SwagExample` (directory, class, bundle name) | Plugin name, PascalCase, class name = file name | e.g. `AcmeShop` |
| `SwagExample\` (namespace) | PSR-4 namespace (`composer.json` `autoload.psr-4` and `extra.shopware-plugin-class`) | e.g. `Acme\Shop\` |
| `swag/example` | Composer package name (decisive for dependencies) | `vendor/package` |
| `swag_example` | Table/entity name (in `uninstall()`) | `<prefix>_<name>` |
| `extra.label` / `extra.description` | Required or optional respectively, per locale (`de-DE`, `en-GB`) | your own texts |
| `~6.7.0` | Constraint on `shopware/core`, do not pin to a patch level | keep |

## composer.json: required fields (according to `PluginFinder`)

`type = shopware-platform-plugin`, `extra.shopware-plugin-class` (FQCN), `extra.label` (not empty), `autoload.psr-4` or `psr-0`. If any of these is missing, the plugin does not show up in `plugin:list`. Optional: `extra.description`, `manufacturerLink`, `supportLink`, `copyright`, `plugin-icon`. Dependencies on other plugins: normal `require`/`conflict` with the Composer name.

## Lifecycle (order)

- Install: pre-event, system config defaults from `config.xml`, `install()`, migrations, custom fields, `installedAt`, `postInstall()`, post-event.
- Update: `update()`, migrations, custom fields, DB version, `postUpdate()`.
- Activate / Deactivate: the kernel is rebooted, `activate()` runs on the new instance (plugin services available). Migrations only run on activate if `activate()` itself sets `setAutoMigrate(true)` (the core sets it to false beforehand).
- Uninstall: `uninstall()` always runs, even with `keepUserData()`. The core deletes migration entries, system config and custom fields (without `keepUserData`), but NOT your own tables/columns: `uninstall()` takes care of that (the template contains the `keepUserData` guard and `DROP TABLE IF EXISTS`; remove the lines if there are no tables).
- In `install()` your own plugin services are not available (the container is only rebuilt on activate). The plugin class constructor is `final`; no constructor injection.
- Always make schema changes via migration (see ../migration/README.md), not in `install()`/`update()`.

## Registering services

Loading happens via the glob `Resources/config/services.*` (XML, YAML, PHP). XML is deprecated in 6.7 (`Bundle::registerContainerFile`, removal in 6.8.0.0, triggers a deprecation). Therefore: use `services.php`. `services.xml.dist` deliberately has an extension that is not loaded; if you need XML, rename it and delete `services.php` (otherwise both are loaded).

Excerpt `src/Resources/config/services.php`, variant A (explicit, core style, used in the templates):

```php
$services->set(\SwagExample\Service\MyService::class)
    ->args([service(\Shopware\Core\System\SystemConfig\SystemConfigService::class)]);

$services->set(\SwagExample\Subscriber\MySubscriber::class)
    ->tag('kernel.event_subscriber');
```

Variant B (autowire + autoconfigure, scan `src`). Verified live: plugin installed and activated, subscriber and decorator work; autoconfigure only takes effect with `->defaults()->autowire()->autoconfigure()`:

```php
$services->defaults()->autowire()->autoconfigure();

$services->load('SwagExample\\', __DIR__ . '/../../')
    ->exclude([__DIR__ . '/../../Resources', __DIR__ . '/../../Migration', __DIR__ . '/../../SwagExample.php']);

// scalar arguments cannot be autowired
$services->set(\SwagExample\Service\MyService::class)->arg('$greeting', 'Hello');
```

There is no autowiring default: without `defaults()->autowire()` arguments must be specified via `->args([...])` and tags via `->tag(...)`. Controllers, routes and services retrieved via `$container->get()` need `->public()`. Details and tag overview: ../../references/05-registering-services.md.

## Integration / CLI

```bash
# Copy the scaffold to custom/plugins/ (or custom/static-plugins/) and replace the placeholders
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample
bin/console cache:clear

# Alternatively let the core generate a scaffold (creates services.php incrementally)
bin/console plugin:create SwagExample SwagExample

bin/console plugin:update SwagExample -c          # after a version bump in composer.json + plugin:refresh
bin/console plugin:deactivate SwagExample
bin/console plugin:uninstall SwagExample --keep-user-data
bin/console plugin:uninstall SwagExample          # deletes data (cleanup code in uninstall())
```

Check: `bin/console debug:container <ServiceId>`, `bin/console lint:container` (standard Symfony commands).

## Optional plugin methods (commented out in the template)

`executeComposerCommands(): bool` (default `false`, runs `composer require/remove`; has no effect with `shopware.deployment.cluster_setup`), `rebuildContainer(): bool` (default `true`, only effective in the CLI), `enrichPrivileges(): array`, `configureRoutes(...)`. If `build()` is overridden, always call `parent::build($container)`.

## References

- ../../references/02-plugin-structure.md
- ../../references/03-plugin-lifecycle.md
- ../../references/05-registering-services.md
- ../../references/01-project-structure-dev-environment.md
- ../../references/49-build-deployment.md
- Matching templates: ../config/README.md, ../migration/README.md, ../entity/README.md, ../event-subscriber/README.md, ../decorator/README.md, ../theme/README.md

## Notes and gaps

- Deprecations (6.7, removal in 6.8.0.0): `services.xml`, `routes.xml` and package config as XML; `plugin:list --json` (use `--format json` instead). `config.xml` (plugin configuration) is, to current knowledge, not affected (assumption).
- Verified live (review rounds 1–3): template installed, activated and uninstalled (with and without `--keep-user-data`) in Shopware 6.7.15.1. `composer.json` checked as JSON; signatures of the lifecycle methods and contexts checked against `core/Framework/Plugin.php`.
- ⚠ GAP: `plugin.png` (icon) is not included. The plugin class uses `$this->container` (Symfony `Bundle`); in `install()` only fetch core services from it.
- ⚠ GAP: The behavior of a plugin's own subscriber on `Plugin*Event` before activation is not established (the container only contains plugin services after activation).
- ⚠ GAP: Zip plugins with third-party libraries (shipping `vendor/`, store limit) are only described in the reference, not as a template.
