# Template: testing (PHPUnit for PHP plugins)

Shopware 6.7.15.1. PHPUnit scaffold for a plugin: `phpunit.xml.dist`, test bootstrap, one unit test, two integration tests (DAL and Store API). Reference: ../../references/46-tests-phpunit.md. Related templates: ../plugin/README.md (plugin root), ../quality/README.md (PHPStan, code style), ../testing-js/README.md (Jest/Vitest for admin and storefront).

Tested in practice in the project's Docker setup (PHP 8.4.27, Composer 2.10.3, MariaDB 11.8): all three tests ran green, with PHPUnit 11.5.57 and 13.4.5. For details and limits see "Checked / gaps" below.

## File tree

```
SwagExample/
├── composer.json.snippet                    # excerpt: autoload-dev (plugin), require-dev + scripts (project)
├── phpunit.xml.dist                         # suites unit + integration, APP_ENV=test (force)
├── src/Service/PriceFormatter.php           # only the test subject of the examples
└── tests/
    ├── TestBootstrap.php                    # TestBootstrapper + addPsr4 for the test namespace
    ├── Unit/Service/PriceFormatterTest.php  # without kernel/DB (StaticSystemConfigService)
    └── Integration/
        ├── Repository/ProductRepositoryTest.php   # DAL: ProductBuilder + product.repository
        └── Route/ProductStoreApiTest.php          # Store API with getSalesChannelBrowser()
```

Folder structure following Symfony/Shopware convention: `tests/` mirrors `src/` (`src/Service/X.php` -> `tests/Unit/Service/XTest.php`), test namespace `SwagExample\Tests\`. Root scaffold of the plugin (composer.json, plugin class, services.php): ../plugin/README.md. Registering `PriceFormatter` in `services.php` is only necessary if an integration test fetches the service from the container (then `->public()`, see pitfalls; example in ../plugin/README.md).

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `SwagExample` / `SwagExample\Tests\` | Plugin name and test namespace; `addCallingPlugin()` derives the plugin name from `extra.shopware-plugin-class` in composer.json | your own plugin name |
| `SwagExample.config.suffix` | System config key of the example | your own key |
| `PriceFormatter`, `ProductBuilder` data | Example objects | your own classes/fixtures |

## Setup

1. Dev tools go into the project (not into the plugin): the project Composer does not install a plugin's `require-dev`.
   Variant A (standard, in the project): `docker compose exec web composer require --dev shopware/dev-tools phpunit/phpunit symfony/browser-kit`. `shopware/dev-tools` 1.6.0 itself requires `phpunit/phpunit` (`^9.6 | ^10 | ^11.4 | ^12 | ^13`) and `symfony/browser-kit` (`^5.4 | ^6 | ^7`), so `composer require --dev shopware/dev-tools` alone pulls in both. This was resolved via `composer require --dev --dry-run` in the project (PHPUnit 13.4.5, browser-kit 7.4.21, dom-crawler 8.1.5, dev-tools 1.6.0), but NOT actually installed (that changes the project's `composer.json` and `composer.lock`; ⚠ UNVERIFIED whether the runs are then identical).
   Variant B (without touching the project, tried in practice): separate directory in the container:
   ```bash
   docker compose exec web sh -c 'mkdir -p /tmp/qa && cd /tmp/qa && echo "{}" > composer.json \
     && composer require --no-interaction phpunit/phpunit symfony/browser-kit:^7.4'
   ```
   `/tmp` is not in the volume and is lost when the container is recreated.
2. Copy `composer.json.snippet` into the plugin `composer.json` (`autoload-dev`, `php`), copy `phpunit.xml.dist` and `tests/` into the plugin.
3. Test bootstrap: `tests/TestBootstrap.php` lets `TestBootstrapper` set up the test DB (`<DB>_test`) and install the plugin; `addPsr4` registers the test namespace (the project Composer does not evaluate the plugin's `autoload-dev`).
4. DB permissions: see the next section; this is the most common hurdle in the Docker setup.

## Running in the Docker setup

In the project directory (`/Users/.../shopware`, contains `compose.yaml`); in the container the working directory is `/var/www/html`. All paths are relative to it.

The Compose user `shopware` may only access the database `shopware` (`GRANT ALL PRIVILEGES ON shopware.*`). The bootstrapper needs `CREATE`/`DROP` on `shopware_test`; with the default user it aborts:

```
SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user 'shopware'@'%' to database 'shopware_test'
```

Solution without changing the DB user and the main database: set `DATABASE_URL` to the root account for the test run only (Compose dev password `root`). The bootstrapper appends `_test` to the DB name: `shopware_test` is used, the database `shopware` is not touched.

```bash
# Variant A: tools in the project (vendor/bin/phpunit)  ⚠ UNVERIFIED (only variant B actually ran)
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist

# Variant B: tools in /tmp/qa (tried). auto_prepend_file is needed so that Shopware\Core\TestBootstrapper is found
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  php -d auto_prepend_file=/var/www/html/vendor/autoload.php /tmp/qa/vendor/bin/phpunit \
  --configuration custom/plugins/SwagExample/phpunit.xml.dist

# Unit only / integration only / single test (append to variant A or B)
  --testsuite unit
  --testsuite integration
  --filter ProductStoreApiTest

# Deprecations against 6.8 (established as an environment variable, ran green)
docker compose exec -e V6_8_0_0=1 -e DATABASE_URL=... web ...
```

Environment used in the container (established): `APP_ENV=dev`, `DATABASE_URL=mysql://shopware:shopware@database:3306/shopware`. The bootstrapper loads `.env` with `Dotenv::bootEnv`, which does not overwrite variables that are already set. That is why `phpunit.xml.dist` sets `APP_ENV=test` with `force="true"` (`<server>` and `<env>`). Without it every integration test aborts with `RuntimeException: Unable to run tests against kernel without test.service_container`.

Every run (even `--testsuite unit`) executes the bootstrap, which needs the test DB: `system:install --create-database --force --drop-database --basic-setup` the first time (test DB missing) and afterwards `plugin:refresh` + `plugin:install --activate --reinstall` per run (because of `setForceInstallPlugins(true)`). `FORCE_INSTALL=1` forces a reinstall of the test DB.

CI and release: the same commands without `docker compose exec`, with a dedicated test DB and a dedicated DB user with CREATE/DROP permission; pipeline integration and build in ../../references/49-build-deployment.md. Dev packages do not go into the shipped plugin ZIP or into the production deployment (`composer install --no-dev`).

## Pitfalls (all encountered in this environment unless noted otherwise)

- `Class "Shopware\Core\TestBootstrapper" not found`: PHPUnit from `/tmp/qa` does not know the project autoloader (`auto_prepend_file`, see above). With installation in the project this does not occur (⚠ UNVERIFIED).
- `Access denied ... shopware_test`: DB user without permissions, see above. Alternative: once as root `GRANT ALL ON shopware_test.* TO 'shopware'@'%'` (⚠ UNVERIFIED, not executed in order not to change the DB user).
- `Unable to run tests against kernel without test.service_container`: `APP_ENV` not `test` (the container sets `dev`).
- `The "...Service" service or alias has been removed or inlined when the container was compiled`: the container removes private services that nobody else references; `getContainer()->get()` does not find them. Test via public entry points (repository, Store API, route) or register the service in `services.php` with `->public()`.
- Test plugin classes not found: `addPsr4` missing in the bootstrap.
- Store/Admin API tests without `symfony/browser-kit`: fatal error (derived from the source code, not triggered here).
- Code coverage: neither Xdebug nor PCOV is loaded in the container (`php -m`), so `--coverage-*` yields no data (⚠ UNVERIFIED, not attempted).
- `ProductBuilder`, `IdsCollection` are `@internal`/`@final`: no BC guarantee.
- The PHPUnit cache `.phpunit.cache/` is created in the plugin folder: add it to `.gitignore`.

## Checked / gaps

- Checked (2026-10-28): `unit` (1 test), `integration` (2 tests, DAL + Store API), `--filter`, `V6_8_0_0=1`, PHPUnit 11.5.57 and 13.4.5, with the project's Symfony components (7.4) plus `symfony/dom-crawler` 8.1.5 from `/tmp/qa`.
- ⚠ UNVERIFIED: Installing the tools directly in the project (`vendor/bin/phpunit`), PHPUnit 12, the `->public()` variant, coverage, the `GRANT` solution, unit runs without a DB (custom lean bootstrap without `TestBootstrapper`).
- ⚠ GAP: PHPUnit version recommended by Shopware for 6.7.15.1 not established (only: `shopware/dev-tools` 1.6.0 allows `^9.6` to `^13`).
