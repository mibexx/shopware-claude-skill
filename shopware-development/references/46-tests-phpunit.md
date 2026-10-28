# PHP tests with PHPUnit (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when PHPUnit tests are set up or written for a plugin: bootstrap, unit tests without a kernel, integration tests with a database, Store/Admin API tests, fixtures.

Scope: JS tests (Jest/Vitest) in [T-47](./47-tests-jest.md); PHPStan/code style in [T-48](./48-static-analysis.md); plugin scaffold in [T-02](./02-plugin-structure.md); conventions in [T-45](./45-coding-guidelines.md).

Runnable template with setup, execution in the Docker setup and pitfalls: [../templates/testing/README.md](../templates/testing/README.md); code quality (PHPStan, php-cs-fixer): [../templates/quality/README.md](../templates/quality/README.md); JS tests: [../templates/testing-js/README.md](../templates/testing-js/README.md). The same examples were actually run in the project's Docker setup (section 3, step 5; Sources).

## 2. Concepts

- **Bootstrap:** `Shopware\Core\TestBootstrapper` (`core/TestBootstrapper.php`) loads `.env`, appends `_test` to the DB name, boots the kernel and, if the schema is missing, runs `system:install --create-database --force --drop-database --basic-setup --no-assign-theme` (this **drops the test DB**). `addCallingPlugin()` reads the plugin name from `composer.json` (`extra.shopware-plugin-class`, last name segment). `addActivePlugins('Name')` installs/activates further plugins. `setForceInstallPlugins(true)` forces `plugin:refresh` and `plugin:install --activate --reinstall`, even if a test DB exists. Further methods (verified): `setPlatformEmbedded`, `setDatabaseUrl`, `getDatabaseUrl`, `getClassLoader`, `setProjectDir`, `setForceInstall`, `setLoadEnvFile`, `setEnableCommercial`.
- **Unit test:** no kernel, no container; instantiate services directly, dependencies as stubs. Helpers: `Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository`, `…\Stub\SystemConfigService\StaticSystemConfigService`, `…\Stub\Framework\IdsCollection`.
- **Integration test:** trait `IntegrationTestBehaviour` (bundles `KernelTestBehaviour`, `DatabaseTransactionBehaviour`, among others); each test runs in a transaction that is rolled back. Container via `static::getContainer()`.
- **API tests:** `SalesChannelApiTestBehaviour` (`getSalesChannelBrowser()` creates a test sales channel and sets `sw-access-key`; `login()`, `createSalesChannelContext()`); `AdminApiTestBehaviour::getBrowser()`.
- **Further traits:** `BasicTestDataBehaviour` (`getValidPaymentMethodId()`, `getValidCountryId()`, `getDeDeLanguageId()`), `QueueTestBehaviour`, `EnvTestBehaviour`, `AppSystemTestBehaviour`, `MailTemplateTestBehaviour`, `CacheTestBehaviour`.
- **Fixtures:** `IdsCollection` (stable UUIDs per key: `create`, `get`, `getIdArray`, `getBytes`, `getList`, `all`, `prefixed`); `ProductBuilder` (`core/Content/Test/Product/ProductBuilder.php`, `build()`, `write($container)`).
- **Stability:** test helpers are mostly `@internal` or `@final` (`ProductBuilder`, `KernelLifecycleManager` `@internal`, `IdsCollection` `@final`); no BC guarantee, plugins use them anyway.
- **Prerequisite:** the installation contains **no PHPUnit** and no `symfony/browser-kit` (no `vendor/phpunit`, no `require-dev`); a dev setup is required (section 3, step 1). Even a pure unit run needs the test DB with this bootstrap (the bootstrapper boots the kernel and installs if needed).

```
custom/plugins/SwagExample/
├── composer.json                  # extra.shopware-plugin-class, autoload-dev
├── phpunit.xml.dist
├── src/{SwagExample.php, Service/PriceFormatter.php, ...}
└── tests/
    ├── TestBootstrap.php
    ├── Unit/Service/PriceFormatterTest.php
    └── Integration/Route/ExampleRouteTest.php
```

Reference in core: `core/TestBootstrap.php` (`setPlatformEmbedded(false)`, `setEnableCommercial()`), stubs under `core/Test/Stub/…`, traits under `core/Framework/Test/TestCaseBase/`, fixture traits `core/Test/Integration/Traits/{CustomerTestTrait,EntityFixturesBase,OrderFixture}.php`.

## 3. Instructions

1. Dev dependencies go into the **project** (the project Composer does not install a plugin's `require-dev`): `docker compose exec web composer require --dev shopware/dev-tools` pulls in both, according to the Packagist metadata of `shopware/dev-tools` 1.6.0 (`require`: `phpunit/phpunit ^9.6 | ^10 | ^11.4 | ^12 | ^13`, `symfony/browser-kit ^5.4 | ^6 | ^7`, Faker, Web Profiler). Resolved in the project via `composer require --dev --dry-run shopware/dev-tools phpunit/phpunit symfony/browser-kit` (Composer 2.10.3, PHP 8.4.27): PHPUnit 13.4.5, `symfony/browser-kit` 7.4.21, `symfony/dom-crawler` 8.1.5, `shopware/dev-tools` 1.6.0, with no conflict with the project packages (Symfony 7.4). `symfony/browser-kit` is mandatory for `SalesChannelApiTestBehaviour`/`AdminApiTestBehaviour` (they return a `KernelBrowser`; without the package: fatal error). PHPUnit ≥ 10 (the traits use `PHPUnit\Framework\Attributes` such as `#[Before]`/`#[After]`). In practice the tests were run with PHPUnit 11.5.57 and 13.4.5 from a separate directory `/tmp/qa` in the container (no change to `composer.json`; invoked with `php -d auto_prepend_file=/var/www/html/vendor/autoload.php /tmp/qa/vendor/bin/phpunit …`, see [../templates/testing/README.md](../templates/testing/README.md)). ⚠ UNVERIFIED: actual installation into the project (dry run only), PHPUnit 12. ⚠ GAP: the PHPUnit version recommended by Shopware for 6.7.15.1 is not documented.
2. Extend the plugin's `composer.json` with `extra.shopware-plugin-class` and `autoload-dev`. The project Composer does not evaluate a plugin's `autoload-dev`; the test bootstrap registers the test namespace via `addPsr4` (section 4).
3. Create `phpunit.xml.dist` and `tests/TestBootstrap.php` (section 4).
4. DB: `TestBootstrapper::getDatabaseUrl()` takes `DATABASE_URL` and appends `_test` (`shopware` becomes `shopware_test`); custom URL via `->setDatabaseUrl(...)`. The DB user needs `CREATE/DROP`. In the project's Docker setup the user `shopware` only has `ALL PRIVILEGES ON shopware.*`: the run aborts with `1044 Access denied for user 'shopware'@'%' to database 'shopware_test'`. Solved with `-e DATABASE_URL=mysql://root:root@database:3306/shopware` (for the test run only; Compose dev root password `root`); `shopware_test` is then created and the main database stays untouched. ⚠ UNVERIFIED: permanent `GRANT` on `shopware_test.*`.
5. Running in the Docker setup (from the project directory containing `compose.yaml`; in the container `/var/www/html`, paths relative to it):

```bash
# Tools installed in the project (⚠ UNVERIFIED, dry run only)
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist
docker compose exec -e DATABASE_URL=... web vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist --testsuite unit
docker compose exec -e DATABASE_URL=... web vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist --filter ExampleRouteTest

# Tools in /tmp/qa (actually run, green: 3 tests, 5 assertions)
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  php -d auto_prepend_file=/var/www/html/vendor/autoload.php /tmp/qa/vendor/bin/phpunit \
  --configuration custom/plugins/SwagExample/phpunit.xml.dist
```

   Environment in the container: `APP_ENV=dev` and `DATABASE_URL` pointing to `shopware`. So that the kernel starts with `test.service_container`, `phpunit.xml.dist` sets `APP_ENV=test` with `force="true"` (`<server>` and `<env>`); without it all integration tests fail with `Unable to run tests against kernel without test.service_container`. Every run (including `--testsuite unit`) boots the kernel: the first run executes `system:install --create-database --drop-database --basic-setup` for `shopware_test`, after that each run executes `plugin:refresh` and `plugin:install --activate --reinstall` (because of `setForceInstallPlugins(true)`); `FORCE_INSTALL=1` reinstalls the test DB. Code coverage: neither Xdebug nor PCOV is loaded in the container (⚠ UNVERIFIED). CI/release: the same commands without `docker compose exec`, own test DB with CREATE/DROP privileges, dev packages not in the plugin ZIP/production deployment ([T-49](./49-build-deployment.md)).

6. Unit tests: `#[CoversClass]` of exactly one class, stubs instead of mocks. Repositories: `StaticEntityRepository::of(Collection::class, [[…results per call…]])`; write payloads afterwards in `->creates`, `->updates`, `->upserts`, `->deletes`.
7. Integration tests: include the traits, container via `static::getContainer()`.
8. Store API: `$this->getSalesChannelBrowser()`, `$this->login($browser)` (customer `…@example.com`, password `shopware`, sets `sw-context-token`). Admin API: `AdminApiTestBehaviour`, `assertEntityExists($browser, 'product', $id)`.
9. Testing deprecations against 6.8: `docker compose exec -e V6_8_0_0=1 -e DATABASE_URL=… web vendor/bin/phpunit …` (see [T-45](./45-coding-guidelines.md); with `V6_8_0_0=1` the Store API test ran green in the Docker setup).

## 4. Examples

`composer.json` (excerpt)

```json
{
  "name": "swag/example",
  "type": "shopware-platform-plugin",
  "autoload": { "psr-4": { "SwagExample\\": "src/" } },
  "autoload-dev": { "psr-4": { "SwagExample\\Tests\\": "tests/" } },
  "extra": { "shopware-plugin-class": "SwagExample\\SwagExample" }
}
```

`phpunit.xml.dist` (PHPUnit 10+/11 schema; the docs still show the 9.3 schema; complete, executed version in [../templates/testing/](../templates/testing/README.md))

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="../../../vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/TestBootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         executionOrder="random">
    <php>
        <ini name="error_reporting" value="-1"/>
        <server name="KERNEL_CLASS" value="Shopware\Core\Kernel"/>
        <server name="APP_ENV" value="test" force="true"/>
        <env name="APP_ENV" value="test" force="true"/>
        <env name="APP_DEBUG" value="1"/>
    </php>
    <testsuites>
        <testsuite name="unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="integration"><directory>tests/Integration</directory></testsuite>
    </testsuites>
    <source><include><directory>src</directory></include></source>
</phpunit>
```

`tests/TestBootstrap.php`

```php
<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$bootstrapper = (new TestBootstrapper())
    ->setPlatformEmbedded(true) // plugin lives in custom/plugins (default: true)
    ->addCallingPlugin()
    ->setForceInstallPlugins(true)
    ->bootstrap();

// Register the test namespace explicitly: the plugin's autoload-dev does not apply in the project Composer.
$bootstrapper->getClassLoader()->addPsr4('SwagExample\\Tests\\', __DIR__);
```

Evidence: `TestBootstrapper::addPluginAutoloadDev()` (reads `autoload-dev` of the active plugins) is only called in `getClassLoader()`; `bootstrap()` itself uses `vendor/autoload.php` directly. The explicit `addPsr4` is therefore the reliable way. `autoload-dev` in the plugin `composer.json` remains useful for IDE/standalone runs.

`src/Service/PriceFormatter.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class PriceFormatter
{
    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function format(float $price): string
    {
        return \sprintf('%.2F %s', $price, $this->config->getString('SwagExample.config.suffix'));
    }
}
```

`tests/Unit/Service/PriceFormatterTest.php` (without kernel)

```php
<?php declare(strict_types=1);

namespace SwagExample\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use SwagExample\Service\PriceFormatter;

#[CoversClass(PriceFormatter::class)]
class PriceFormatterTest extends TestCase
{
    public function testUsesConfiguredSuffix(): void
    {
        $config = new StaticSystemConfigService(['SwagExample.config.suffix' => 'EUR']);

        static::assertSame('9.90 EUR', (new PriceFormatter($config))->format(9.9));
    }
}
```

`tests/Integration/Route/ExampleRouteTest.php` (Store API, DB, ProductBuilder)

```php
<?php declare(strict_types=1);

namespace SwagExample\Tests\Integration\Route;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

class ExampleRouteTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testProductIsReadableViaStoreApi(): void
    {
        $browser = $this->getSalesChannelBrowser(); // creates a sales channel
        $ids = new IdsCollection();

        (new ProductBuilder($ids, 'SW-T46'))
            ->price(100)
            ->visibility($this->getSalesChannelApiSalesChannelId())
            ->write(static::getContainer());

        $browser->request('POST', '/store-api/product', ['ids' => [$ids->get('SW-T46')]]);

        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertSame(200, $browser->getResponse()->getStatusCode());
        static::assertSame($ids->get('SW-T46'), $response['elements'][0]['id']);
    }
}
```

Verified: `PriceFormatterTest` (unit) and the Store API test (here `ExampleRouteTest`, in the template `ProductStoreApiTest` with `SW-QA`) ran green in the Docker setup (PHPUnit 11.5.57 and 13.4.5; additionally a DAL test with `ProductBuilder`/`product.repository`, see template). An integration test that fetched `PriceFormatter` directly via `static::getContainer()->get(PriceFormatter::class)` failed as long as the private service was not referenced anywhere else (see pitfalls).

## 5. Pitfalls

- Fatal error "Class Symfony\Component\BrowserKit\… not found" in the test behaviours (`SalesChannelApiTestBehaviour`, `AdminApiTestBehaviour`): `symfony/browser-kit` is missing.
- Test classes `SwagExample\Tests\…` not found: `addPsr4` is missing in the test bootstrap (plugin `autoload-dev` is not loaded).
- `Class "Shopware\Core\TestBootstrapper" not found` (PHPUnit from the separate directory `/tmp/qa`): project autoloader not loaded; use `php -d auto_prepend_file=/var/www/html/vendor/autoload.php` (not needed when installed in the project, ⚠ UNVERIFIED).
- `Unable to run tests against kernel without test.service_container`: `APP_ENV` is `dev` (the container variable wins over `.env`/`phpunit.xml`); set `<server name="APP_ENV" value="test" force="true"/>`.
- `1044 Access denied … to database 'shopware_test'`: Compose user `shopware` only has privileges on `shopware`; pass `DATABASE_URL` with the root user (step 4).
- `The "…" service or alias has been removed or inlined when the container was compiled` on `getContainer()->get(MeinService::class)`: the container removes unused private services; test via public entry points or set `->public()`.
- Test DB is reinstalled every time or `system:install failed`: DB user without `CREATE/DROP` privileges or `DATABASE_URL` missing; check which `…_test` DB is actually used.
- Plugin tables missing in an existing test DB: `setForceInstallPlugins(true)` is missing.
- "The previous test case's transaction was not closed properly" / "Too many Nesting Levels" (`DatabaseTransactionBehaviour`): own transactions not closed, or DDL/`TRUNCATE` in the test (implicit commit).
- `getSalesChannelApiSalesChannelId()` throws a `LogicException` if no browser was created beforehand.
- `ProductBuilder::visibility()` defaults to `TestDefaults::SALES_CHANNEL`; for the test browser, pass the dynamically created sales channel ID.
- Kernel/traits in unit tests: slow and against the core rules; `createMock()` without `->expects()`: `createStub()` is sufficient.
- Old docs examples (PHPUnit 9.3 schema, `@covers` annotations) only work to a limited extent with current PHPUnit: use attributes and `<source>` instead of `<coverage><include>`.
- Fixtures do not survive the transaction; `SalesChannelApiTestBehaviour` additionally deletes its sales channel in `#[After]`.
- The feature flag attribute `Shopware\Core\Test\Annotation\DisabledFeatures` is `@internal` and intended for core tests.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Documented (6.7.15.1): lifecycle via PHPUnit attributes `#[Before]`/`#[After]`; the bootstrapper uses `CompletionGuard::register()` and PHPUnit extensions (`core/Test/PHPUnit/Extension/{FeatureFlag,DatabaseDiff,Datadog,Common}`).
- Stubs (`core/Test/Stub/…`, generic `StaticEntityRepository::of()`) are the basis for unit tests. ⚠ UNVERIFIED: in 6.5/6.6 `StaticEntityRepository` commonly lived in the namespace `Shopware\Core\Framework\Test\…` (from memory only); whether 6.5 has identical `TestBootstrapper` signatures was not checked.

## 6. Checklist

- [ ] `phpunit/phpunit` ≥ 10 and `symfony/browser-kit` (also recommended: `shopware/dev-tools`) installed as dev dependencies
- [ ] `autoload-dev` and `extra.shopware-plugin-class` in `composer.json`
- [ ] `tests/TestBootstrap.php` with `addCallingPlugin()`, `setForceInstallPlugins(true)` and `getClassLoader()->addPsr4(...)` for the test namespace
- [ ] `phpunit.xml.dist` with suites `unit` and `integration`, `<source>` instead of `<coverage>`
- [ ] Test DB (`…_test`) reachable with CREATE/DROP privileges (Docker: `DATABASE_URL` with root user, see step 4)
- [ ] `APP_ENV=test` via `<server … force="true"/>` in `phpunit.xml.dist` (the container sets `dev`)
- [ ] Unit tests without kernel, `#[CoversClass]`, stubs
- [ ] Integration tests with `IntegrationTestBehaviour`; no unclosed transactions
- [ ] Store API tests with `getSalesChannelBrowser()` and the matching sales channel ID
- [ ] Run with `V6_8_0_0=1` in CI

## 7. Sources

Docs: https://developer.shopware.com/docs/guides/plugins/plugins/testing/php-unit.html (reachable; PHPUnit 9.3 examples), https://developer.shopware.com/docs/resources/guidelines/code/core/unit-tests.html. `/docs/guides/development/testing/` not fetched.

Source code (under `vendor/shopware/`): `core/TestBootstrapper.php` (`bootstrap`, `addCallingPlugin`, `addActivePlugins`, `setForceInstallPlugins`), `core/TestBootstrap.php`, `core/Framework/Test/TestCaseBase/{IntegrationTestBehaviour,KernelTestBehaviour,DatabaseTransactionBehaviour,SalesChannelApiTestBehaviour,AdminApiTestBehaviour,BasicTestDataBehaviour,KernelLifecycleManager,QueueTestBehaviour,EnvTestBehaviour}.php`, `core/Framework/Test/TestKernel.php`, `core/Test/AppSystemTestBehaviour.php`, `core/Test/Stub/Framework/IdsCollection.php`, `core/Test/Stub/DataAbstractionLayer/{StaticEntityRepository,StaticSalesChannelRepository}.php`, `core/Test/Stub/SystemConfigService/StaticSystemConfigService.php`, `core/Content/Test/Product/ProductBuilder.php`.

Hands-on verification (2026-10-28, project Docker setup, container `web`: PHP 8.4.27, Composer 2.10.3, network/Packagist reachable, MariaDB 11.8, DB user `shopware` only on `shopware.*`): tools installed in `/tmp/qa`, temporary plugin created from the templates, `unit` + `integration` green (3 tests); everything removed afterwards. Packagist: `shopware/dev-tools` 1.6.0 (metadata).

### Gaps and uncertainties

- Verified in practice: unit, DAL and Store API tests run (PHPUnit 11.5.57 and 13.4.5), Docker-specific pitfalls (DB privileges, `APP_ENV`, autoloader, private services).
- ⚠ UNVERIFIED: actual installation of the dev tools into the project (only resolved with `--dry-run`), PHPUnit 12, permanent `GRANT`, coverage (no Xdebug/PCOV in the container), unit runs without DB, Admin API test, mail/queue/app behaviours.
- ⚠ GAP: the PHPUnit version recommended by Shopware for 6.7.15.1 is not documented (`shopware/dev-tools` 1.6.0 allows `^9.6` to `^13`); `/docs/guides/development/testing/` not checked.
- ⚠ GAP: `@internal` for `IntegrationTestBehaviour` & co. not found via grep (documented only for `ProductBuilder`, `KernelLifecycleManager`); API stability not guaranteed.
- ⚠ UNVERIFIED: version comparison with 6.5/6.6 only partially documented.
