# PHP-Tests mit PHPUnit (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn für ein Plugin PHPUnit-Tests eingerichtet oder geschrieben werden: Bootstrap, Unit-Tests ohne Kernel, Integrationstests mit Datenbank, Store-/Admin-API-Tests, Fixtures.

Abgrenzung: JS-Tests (Jest/Vitest) in [T-47](./47-tests-jest.md); PHPStan/Code-Style in [T-48](./48-statische-analyse.md); Plugin-Gerüst in [T-02](./02-plugin-aufbau.md); Konventionen in [T-45](./45-coding-guidelines.md).

Lauffähige Vorlage mit Einrichtung, Ausführung im Docker-Setup und Fallstricken: [../templates/testing/README.md](../templates/testing/README.md); Code-Qualität (PHPStan, php-cs-fixer): [../templates/quality/README.md](../templates/quality/README.md); JS-Tests: [../templates/testing-js/README.md](../templates/testing-js/README.md). Dieselben Beispiele wurden im Docker-Setup des Projekts real ausgeführt (Abschnitt 3, Schritt 5; Quellen).

## 2. Konzepte

- **Bootstrap:** `Shopware\Core\TestBootstrapper` (`core/TestBootstrapper.php`) lädt `.env`, hängt `_test` an den DB-Namen, startet den Kernel und führt bei fehlendem Schema `system:install --create-database --force --drop-database --basic-setup --no-assign-theme` aus (das **droppt die Test-DB**). `addCallingPlugin()` liest den Plugin-Namen aus der `composer.json` (`extra.shopware-plugin-class`, letztes Namenssegment). `addActivePlugins('Name')` installiert/aktiviert weitere Plugins. `setForceInstallPlugins(true)` erzwingt `plugin:refresh` und `plugin:install --activate --reinstall`, auch bei vorhandener Test-DB. Weitere Methoden (verifiziert): `setPlatformEmbedded`, `setDatabaseUrl`, `getDatabaseUrl`, `getClassLoader`, `setProjectDir`, `setForceInstall`, `setLoadEnvFile`, `setEnableCommercial`.
- **Unit-Test:** kein Kernel, kein Container; Services direkt instanziieren, Abhängigkeiten als Stubs. Helfer: `Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository`, `…\Stub\SystemConfigService\StaticSystemConfigService`, `…\Stub\Framework\IdsCollection`.
- **Integrationstest:** Trait `IntegrationTestBehaviour` (bündelt `KernelTestBehaviour`, `DatabaseTransactionBehaviour` u. a.); jeder Test läuft in einer Transaktion, die zurückgerollt wird. Container über `static::getContainer()`.
- **API-Tests:** `SalesChannelApiTestBehaviour` (`getSalesChannelBrowser()` legt Test-Sales-Channel an und setzt `sw-access-key`; `login()`, `createSalesChannelContext()`); `AdminApiTestBehaviour::getBrowser()`.
- **Weitere Traits:** `BasicTestDataBehaviour` (`getValidPaymentMethodId()`, `getValidCountryId()`, `getDeDeLanguageId()`), `QueueTestBehaviour`, `EnvTestBehaviour`, `AppSystemTestBehaviour`, `MailTemplateTestBehaviour`, `CacheTestBehaviour`.
- **Fixtures:** `IdsCollection` (stabile UUIDs je Schlüssel: `create`, `get`, `getIdArray`, `getBytes`, `getList`, `all`, `prefixed`); `ProductBuilder` (`core/Content/Test/Product/ProductBuilder.php`, `build()`, `write($container)`).
- **Stabilität:** Test-Helfer sind größtenteils `@internal` bzw. `@final` (`ProductBuilder`, `KernelLifecycleManager` `@internal`, `IdsCollection` `@final`); keine BC-Garantie, Plugins nutzen sie trotzdem.
- **Voraussetzung:** Die Installation enthält **kein PHPUnit** und kein `symfony/browser-kit` (kein `vendor/phpunit`, keine `require-dev`); Dev-Setup nötig (Abschnitt 3, Schritt 1). Auch ein reiner Unit-Lauf braucht mit diesem Bootstrap die Test-DB (der Bootstrapper bootet den Kernel und installiert bei Bedarf).

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

Referenz im Core: `core/TestBootstrap.php` (`setPlatformEmbedded(false)`, `setEnableCommercial()`), Stubs unter `core/Test/Stub/…`, Traits unter `core/Framework/Test/TestCaseBase/`, Fixtures-Traits `core/Test/Integration/Traits/{CustomerTestTrait,EntityFixturesBase,OrderFixture}.php`.

## 3. Anleitung

1. Dev-Abhängigkeiten ins **Projekt** (das `require-dev` eines Plugins installiert der Projekt-Composer nicht): `docker compose exec web composer require --dev shopware/dev-tools` zieht laut Packagist-Metadaten `shopware/dev-tools` 1.6.0 (`require`: `phpunit/phpunit ^9.6 | ^10 | ^11.4 | ^12 | ^13`, `symfony/browser-kit ^5.4 | ^6 | ^7`, Faker, Web-Profiler) beides nach. Per `composer require --dev --dry-run shopware/dev-tools phpunit/phpunit symfony/browser-kit` im Projekt aufgelöst (Composer 2.10.3, PHP 8.4.27): PHPUnit 13.4.5, `symfony/browser-kit` 7.4.21, `symfony/dom-crawler` 8.1.5, `shopware/dev-tools` 1.6.0, ohne Konflikt mit den Projektpaketen (Symfony 7.4). `symfony/browser-kit` ist zwingend für `SalesChannelApiTestBehaviour`/`AdminApiTestBehaviour` (sie liefern `KernelBrowser`; ohne das Paket Fatal Error). PHPUnit ≥ 10 (Traits nutzen `PHPUnit\Framework\Attributes` wie `#[Before]`/`#[After]`). Praktisch ausgeführt wurden die Tests mit PHPUnit 11.5.57 und 13.4.5 aus einem separaten Verzeichnis `/tmp/qa` im Container (kein Eingriff in `composer.json`; Aufruf mit `php -d auto_prepend_file=/var/www/html/vendor/autoload.php /tmp/qa/vendor/bin/phpunit …`, siehe [../templates/testing/README.md](../templates/testing/README.md)). ⚠ UNGEPRÜFT: reale Installation ins Projekt (nur Dry-Run), PHPUnit 12. ⚠ LÜCKE: von Shopware für 6.7.15.1 empfohlene PHPUnit-Version nicht belegt.
2. `composer.json` des Plugins mit `extra.shopware-plugin-class` und `autoload-dev` ergänzen. Das `autoload-dev` eines Plugins wertet der Projekt-Composer nicht aus; den Test-Namespace registriert der Test-Bootstrap per `addPsr4` (Abschnitt 4).
3. `phpunit.xml.dist` und `tests/TestBootstrap.php` anlegen (Abschnitt 4).
4. DB: `TestBootstrapper::getDatabaseUrl()` nimmt `DATABASE_URL` und hängt `_test` an (`shopware` wird `shopware_test`); eigene URL über `->setDatabaseUrl(...)`. DB-User braucht `CREATE/DROP`. Im Docker-Setup des Projekts hat der User `shopware` nur `ALL PRIVILEGES ON shopware.*`: Lauf bricht ab mit `1044 Access denied for user 'shopware'@'%' to database 'shopware_test'`. Gelöst durch `-e DATABASE_URL=mysql://root:root@database:3306/shopware` (nur für den Testlauf; Compose-Dev-Root-Passwort `root`), dann entsteht `shopware_test`, die Hauptdatenbank bleibt unberührt. ⚠ UNGEPRÜFT: dauerhafter `GRANT` auf `shopware_test.*`.
5. Ausführen im Docker-Setup (aus dem Projektverzeichnis mit `compose.yaml`; im Container `/var/www/html`, Pfade relativ dazu):

```bash
# Tools im Projekt installiert (⚠ UNGEPRÜFT, nur Dry-Run)
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist
docker compose exec -e DATABASE_URL=... web vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist --testsuite unit
docker compose exec -e DATABASE_URL=... web vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist --filter ExampleRouteTest

# Tools in /tmp/qa (praktisch gelaufen, grün: 3 Tests, 5 Assertions)
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  php -d auto_prepend_file=/var/www/html/vendor/autoload.php /tmp/qa/vendor/bin/phpunit \
  --configuration custom/plugins/SwagExample/phpunit.xml.dist
```

   Umgebung im Container: `APP_ENV=dev` und `DATABASE_URL` auf `shopware`. Damit der Kernel mit `test.service_container` startet, setzt `phpunit.xml.dist` `APP_ENV=test` mit `force="true"` (`<server>` und `<env>`); ohne das scheitern alle Integrationstests mit `Unable to run tests against kernel without test.service_container`. Jeder Lauf (auch `--testsuite unit`) bootet den Kernel: erster Lauf `system:install --create-database --drop-database --basic-setup` für `shopware_test`, danach pro Lauf `plugin:refresh` und `plugin:install --activate --reinstall` (wegen `setForceInstallPlugins(true)`); `FORCE_INSTALL=1` installiert die Test-DB neu. Code Coverage: im Container ist weder Xdebug noch PCOV geladen (⚠ UNGEPRÜFT). CI/Release: dieselben Befehle ohne `docker compose exec`, eigene Test-DB mit CREATE/DROP-Recht, Dev-Pakete nicht ins Plugin-ZIP/Produktiv-Deployment ([T-49](./49-build-deployment.md)).

6. Unit-Tests: `#[CoversClass]` genau einer Klasse, Stubs statt Mocks. Repositories: `StaticEntityRepository::of(Collection::class, [[…Ergebnisse je Aufruf…]])`; Schreibpayloads danach in `->creates`, `->updates`, `->upserts`, `->deletes`.
7. Integrationstests: Traits einbinden, Container über `static::getContainer()`.
8. Store-API: `$this->getSalesChannelBrowser()`, `$this->login($browser)` (Kunde `…@example.com`, Passwort `shopware`, setzt `sw-context-token`). Admin-API: `AdminApiTestBehaviour`, `assertEntityExists($browser, 'product', $id)`.
9. Deprecations gegen 6.8 testen: `docker compose exec -e V6_8_0_0=1 -e DATABASE_URL=… web vendor/bin/phpunit …` (siehe [T-45](./45-coding-guidelines.md); mit `V6_8_0_0=1` lief der Store-API-Test im Docker-Setup grün).

## 4. Beispiele

`composer.json` (Auszug)

```json
{
  "name": "swag/example",
  "type": "shopware-platform-plugin",
  "autoload": { "psr-4": { "SwagExample\\": "src/" } },
  "autoload-dev": { "psr-4": { "SwagExample\\Tests\\": "tests/" } },
  "extra": { "shopware-plugin-class": "SwagExample\\SwagExample" }
}
```

`phpunit.xml.dist` (PHPUnit 10+/11-Schema; die Doku zeigt noch das 9.3-Schema; vollständige, ausgeführte Fassung in [../templates/testing/](../templates/testing/README.md))

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
    ->setPlatformEmbedded(true) // Plugin liegt in custom/plugins (Default: true)
    ->addCallingPlugin()
    ->setForceInstallPlugins(true)
    ->bootstrap();

// Test-Namespace explizit registrieren: das autoload-dev des Plugins greift im Projekt-Composer nicht.
$bootstrapper->getClassLoader()->addPsr4('SwagExample\\Tests\\', __DIR__);
```

Beleg: `TestBootstrapper::addPluginAutoloadDev()` (liest `autoload-dev` der aktiven Plugins) wird nur in `getClassLoader()` aufgerufen; `bootstrap()` selbst nutzt `vendor/autoload.php` direkt. Der explizite `addPsr4` ist daher der verlässliche Weg. `autoload-dev` in der Plugin-`composer.json` bleibt für IDE/Standalone-Läufe sinnvoll.

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

`tests/Unit/Service/PriceFormatterTest.php` (ohne Kernel)

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

`tests/Integration/Route/ExampleRouteTest.php` (Store-API, DB, ProductBuilder)

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
        $browser = $this->getSalesChannelBrowser(); // legt Sales Channel an
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

Geprüft: `PriceFormatterTest` (Unit) und der Store-API-Test (hier `ExampleRouteTest`, in der Vorlage `ProductStoreApiTest` mit `SW-QA`) liefen im Docker-Setup grün (PHPUnit 11.5.57 und 13.4.5; zusätzlich ein DAL-Test mit `ProductBuilder`/`product.repository`, siehe Vorlage). Ein Integrationstest, der `PriceFormatter` direkt über `static::getContainer()->get(PriceFormatter::class)` holte, scheiterte, solange der private Service sonst nirgends referenziert war (siehe Fallstricke).

## 5. Fallstricke

- Fatal Error "Class Symfony\Component\BrowserKit\… not found" in den Test-Behaviours (`SalesChannelApiTestBehaviour`, `AdminApiTestBehaviour`): `symfony/browser-kit` fehlt.
- Test-Klassen `SwagExample\Tests\…` nicht gefunden: `addPsr4` im Test-Bootstrap fehlt (Plugin-`autoload-dev` wird nicht geladen).
- `Class "Shopware\Core\TestBootstrapper" not found` (PHPUnit aus separatem Verzeichnis `/tmp/qa`): Projekt-Autoloader nicht geladen, `php -d auto_prepend_file=/var/www/html/vendor/autoload.php` nutzen (bei Installation im Projekt ⚠ UNGEPRÜFT nicht nötig).
- `Unable to run tests against kernel without test.service_container`: `APP_ENV` ist `dev` (Container-Variable gewinnt gegen `.env`/`phpunit.xml`); `<server name="APP_ENV" value="test" force="true"/>` setzen.
- `1044 Access denied … to database 'shopware_test'`: Compose-User `shopware` hat nur Rechte auf `shopware`; `DATABASE_URL` mit Root-User übergeben (Schritt 4).
- `The "…" service or alias has been removed or inlined when the container was compiled` bei `getContainer()->get(MeinService::class)`: ungenutzte private Services entfernt der Container; über öffentliche Einstiegspunkte testen oder `->public()` setzen.
- Test-DB wird jedes Mal neu installiert oder `system:install failed`: DB-User ohne `CREATE/DROP`-Rechte oder `DATABASE_URL` fehlt; prüfen, welche `…_test`-DB wirklich genutzt wird.
- Plugin-Tabellen fehlen in vorhandener Test-DB: `setForceInstallPlugins(true)` fehlt.
- "The previous test case's transaction was not closed properly" / "Too many Nesting Levels" (`DatabaseTransactionBehaviour`): eigene Transaktionen nicht geschlossen oder DDL/`TRUNCATE` im Test (implizites Commit).
- `getSalesChannelApiSalesChannelId()` wirft `LogicException`, wenn zuvor kein Browser erzeugt wurde.
- `ProductBuilder::visibility()` nutzt als Default `TestDefaults::SALES_CHANNEL`; für den Test-Browser die dynamisch erzeugte Sales-Channel-ID übergeben.
- Kernel/Traits in Unit-Tests: langsam und gegen die Core-Regeln; `createMock()` ohne `->expects()`: `createStub()` genügt.
- Alte Doku-Beispiele (PHPUnit-9.3-Schema, `@covers`-Annotationen) funktionieren mit aktuellem PHPUnit nur eingeschränkt: Attribute und `<source>` statt `<coverage><include>` verwenden.
- Fixtures überleben die Transaktion nicht; `SalesChannelApiTestBehaviour` löscht seinen Sales Channel zusätzlich im `#[After]`.
- Feature-Flag-Attribut `Shopware\Core\Test\Annotation\DisabledFeatures` ist `@internal` und für Core-Tests gedacht.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Belegt (6.7.15.1): Lifecycle über PHPUnit-Attribute `#[Before]`/`#[After]`; Bootstrapper nutzt `CompletionGuard::register()` und PHPUnit-Extensions (`core/Test/PHPUnit/Extension/{FeatureFlag,DatabaseDiff,Datadog,Common}`).
- Stubs (`core/Test/Stub/…`, generisches `StaticEntityRepository::of()`) sind die Unit-Test-Basis. ⚠ UNGEPRÜFT: In 6.5/6.6 lag `StaticEntityRepository` verbreitet im Namespace `Shopware\Core\Framework\Test\…` (nur Erinnerung); ob 6.5 identische `TestBootstrapper`-Signaturen hat, nicht geprüft.

## 6. Checkliste

- [ ] `phpunit/phpunit` ≥ 10 und `symfony/browser-kit` (empfohlen auch `shopware/dev-tools`) als Dev-Abhängigkeit installiert
- [ ] `autoload-dev` und `extra.shopware-plugin-class` in `composer.json`
- [ ] `tests/TestBootstrap.php` mit `addCallingPlugin()`, `setForceInstallPlugins(true)` und `getClassLoader()->addPsr4(...)` für den Test-Namespace
- [ ] `phpunit.xml.dist` mit Suites `unit` und `integration`, `<source>` statt `<coverage>`
- [ ] Test-DB (`…_test`) mit CREATE/DROP-Rechten erreichbar (Docker: `DATABASE_URL` mit Root-User, siehe Schritt 4)
- [ ] `APP_ENV=test` per `<server … force="true"/>` in `phpunit.xml.dist` (Container setzt `dev`)
- [ ] Unit-Tests ohne Kernel, `#[CoversClass]`, Stubs
- [ ] Integrationstests mit `IntegrationTestBehaviour`; keine ungeschlossenen Transaktionen
- [ ] Store-API-Tests mit `getSalesChannelBrowser()` und passender Sales-Channel-ID
- [ ] Lauf mit `V6_8_0_0=1` in CI

## 7. Quellen

Doku: https://developer.shopware.com/docs/guides/plugins/plugins/testing/php-unit.html (erreichbar; PHPUnit-9.3-Beispiele), https://developer.shopware.com/docs/resources/guidelines/code/core/unit-tests.html. `/docs/guides/development/testing/` nicht abgerufen.

Quellcode (unter `vendor/shopware/`): `core/TestBootstrapper.php` (`bootstrap`, `addCallingPlugin`, `addActivePlugins`, `setForceInstallPlugins`), `core/TestBootstrap.php`, `core/Framework/Test/TestCaseBase/{IntegrationTestBehaviour,KernelTestBehaviour,DatabaseTransactionBehaviour,SalesChannelApiTestBehaviour,AdminApiTestBehaviour,BasicTestDataBehaviour,KernelLifecycleManager,QueueTestBehaviour,EnvTestBehaviour}.php`, `core/Framework/Test/TestKernel.php`, `core/Test/AppSystemTestBehaviour.php`, `core/Test/Stub/Framework/IdsCollection.php`, `core/Test/Stub/DataAbstractionLayer/{StaticEntityRepository,StaticSalesChannelRepository}.php`, `core/Test/Stub/SystemConfigService/StaticSystemConfigService.php`, `core/Content/Test/Product/ProductBuilder.php`.

Praxisprüfung (28.10.2026, Docker-Setup des Projekts, Container `web`: PHP 8.4.27, Composer 2.10.3, Netzwerk/Packagist erreichbar, MariaDB 11.8, DB-User `shopware` nur auf `shopware.*`): Tools in `/tmp/qa` installiert, temporäres Plugin aus den Vorlagen angelegt, `unit` + `integration` grün (3 Tests); danach alles entfernt. Packagist: `shopware/dev-tools` 1.6.0 (Metadaten).

### Lücken und Unsicherheiten

- Praktisch belegt: Unit-, DAL- und Store-API-Test laufen (PHPUnit 11.5.57 und 13.4.5), Docker-spezifische Fallstricke (DB-Rechte, `APP_ENV`, Autoloader, private Services).
- ⚠ UNGEPRÜFT: reale Installation der Dev-Tools ins Projekt (nur `--dry-run` aufgelöst), PHPUnit 12, dauerhafter `GRANT`, Coverage (kein Xdebug/PCOV im Container), Unit-Läufe ohne DB, Admin-API-Test, Mail-/Queue-/App-Behaviours.
- ⚠ LÜCKE: von Shopware empfohlene PHPUnit-Version für 6.7.15.1 nicht belegt (`shopware/dev-tools` 1.6.0 erlaubt `^9.6` bis `^13`); `/docs/guides/development/testing/` nicht geprüft.
- ⚠ LÜCKE: `@internal` für `IntegrationTestBehaviour` & Co. per grep nicht gefunden (belegt nur für `ProductBuilder`, `KernelLifecycleManager`); API-Stabilität nicht zugesichert.
- ⚠ UNGEPRÜFT: Versionsvergleich zu 6.5/6.6 nur teilweise belegt.
